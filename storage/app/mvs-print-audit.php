<?php
require '/var/www/mvscommerce/vendor/autoload.php';
$app = require '/var/www/mvscommerce/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$app->instance('request', Illuminate\Http\Request::create('https://app.mvscommerce.com'));
config(['session.driver' => 'array', 'cache.default' => 'array']);
$db = Illuminate\Support\Facades\DB::connection();
$db->beginTransaction();
$db->statement('SET TRANSACTION READ ONLY');
try {
    $signing = app(App\Services\MvsPrint\QzSigningService::class);
    if (!$signing->isConfigured() || !$signing->certificateConfigured()) throw new RuntimeException('Signing files missing; no generation permitted.');
    $pem = $signing->certificatePem();
    $exact = hash('sha256', '{"call":"print","params":{"audit":true},"timestamp":123456789}');
    $signature = $signing->sign($exact);
    $parsed = openssl_x509_parse($pem);
    echo json_encode([
        'environment' => app()->environment(),
        'certificate_current' => $parsed['validFrom_time_t'] <= time() && $parsed['validTo_time_t'] > time(),
        'certificate_sha256' => openssl_x509_fingerprint($pem, 'sha256'),
        'certificate_key_match_and_signature_valid' => openssl_verify($exact, base64_decode($signature), $pem, OPENSSL_ALGO_SHA512) === 1,
    ]).PHP_EOL;
    $contexts = [];
    foreach (App\Models\User::where('is_active', true)->where('is_platform_admin', false)->orderBy('id')->get() as $user) {
        foreach ($user->companies()->where('companies.is_active', true)->get() as $company) {
            if (!$user->hasPermission('pos.acceder', $company)) continue;
            $branch = $user->branches()->where('branches.company_id', $company->id)->where('branches.is_active', true)->first();
            if (!$branch) continue;
            $kind = $user->hasPermission('dashboard.admin', $company) ? 'admin' : 'cashier';
            $contexts[$kind] ??= [$user, $company, $branch];
        }
    }
    foreach ($contexts as $kind => [$user, $company, $branch]) {
        auth()->setUser($user);
        session()->put(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $token = session()->token() ?: 'mvs-print-read-only-audit';
        session()->put('_token', $token);
        $sale = App\Models\Sale::where('company_id', $company->id)->where('branch_id', $branch->id)->where('status', 'completed')->latest('id')->first();
        $cases = [['GET', '/mvs/print/certificate', []], ['POST', '/mvs/print/signature', ['request' => $exact, '_token' => $token]]];
        if ($sale) $cases[] = ['GET', '/mvs/print/ticket/'.$sale->id, []];
        foreach ($cases as [$method, $path, $parameters]) {
            $request = Illuminate\Http\Request::create('https://app.mvscommerce.com'.$path, $method, $parameters, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTPS' => 'on']);
            $response = $kernel->handle($request);
            $result = ['role' => $kind, 'endpoint' => preg_replace('~/ticket/\d+~', '/ticket/{sale}', $path), 'status' => $response->getStatusCode(), 'content_type' => $response->headers->get('Content-Type')];
            if (str_contains($path, '/certificate')) $result['pem'] = str_contains($response->getContent(), 'BEGIN CERTIFICATE');
            if (str_contains($path, '/signature') && $response->isSuccessful()) $result['signature_valid'] = openssl_verify($exact, base64_decode($response->getContent()), $pem, OPENSSL_ALGO_SHA512) === 1;
            if (str_contains($path, '/ticket/')) $result['qz'] = json_decode($response->getContent(), true)['qz'] ?? null;
            echo json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;
        }
    }
    echo 'BUSINESS_WRITES=0 (PostgreSQL READ ONLY transaction)'.PHP_EOL;
} finally {
    $db->rollBack();
}
