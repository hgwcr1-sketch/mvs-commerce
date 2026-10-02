<?php

// Standalone probe: creates its own disposable database; never reads .env DB settings.
// Run: php tests/manual/cobros-6b-concurrency.php
use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\CobrosReceipt;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\SecurePaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Feature\CobrosPaymentTest;

require __DIR__.'/../../vendor/autoload.php';
$worker = ($argv[1] ?? '') === 'worker';
$directory = $worker ? ($argv[2] ?? '') : sys_get_temp_dir().'/cobros-6b-'.bin2hex(random_bytes(8));
if (! $worker) {
    mkdir($directory);
    touch($directory.'/exclusive.sqlite');
    file_put_contents($directory.'/probe-marker', 'cobros-6b');
}
if (file_get_contents($directory.'/probe-marker') !== 'cobros-6b'
    || ! str_starts_with(realpath($directory), realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'cobros-6b-')) {
    throw new RuntimeException('Exclusive test directory required');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/exclusive.sqlite', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4', 'APP_CONFIG_CACHE' => $directory.'/no-config.php', 'VIEW_COMPILED_PATH' => $directory] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement('PRAGMA busy_timeout = 15000');
$check = function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
if ($worker) {
    $fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $index = $argv[3];
    // Both processes reach the real receipt INSERT after checking the same old balance.
    $arrived = false;
    DB::connection()->beforeExecuting(function ($query) use ($directory, $index, &$arrived) {
        if (! $arrived && str_starts_with($query, 'insert into "cobros_receipts"')) {
            $arrived = true;
            touch($directory.'/ready-'.$index);
            $deadline = microtime(true) + 20;
            while (! file_exists($directory.'/release')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Barrier timeout');
                }
                usleep(10000);
            }
        }
    });
    try {
        $result = app(SecurePaymentService::class)->register(User::findOrFail($fixture['user']), Company::findOrFail($fixture['company']), AccountReceivable::findOrFail($fixture['account']), $fixture['payload']);
        echo json_encode(['payment' => $result['payment']->id, 'receipt' => $result['receipt']->id, 'replayed' => $result['replayed']], JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e).': '.$e->getMessage());
        exit(1);
    }
    exit;
}
$check($app->make(Kernel::class)->call('migrate', ['--force' => true]) === 0, 'Test migrations failed');
// Reuse the existing financial fixtures without running PHPUnit lifecycle/transactions.
$test = new CobrosPaymentTest('test_partial_payment_reduces_balance_and_creates_unique_receipt');
$invoke = fn (string $method, array $args = []) => (new ReflectionMethod($test, $method))->invokeArgs($test, $args);
[$company, $branch, $user] = $invoke('context');
[, $account] = $invoke('customerWithAccount', [$company, $branch, $user, '3000.0000']);
$method = $invoke('method', [$company, 'cash']);
$payload = $invoke('payload', ['1000.00', $method, '6B concurrent retry']);
file_put_contents($directory.'/fixture.json', json_encode(['user' => $user->id, 'company' => $company->id, 'account' => $account->id, 'payload' => $payload], JSON_THROW_ON_ERROR));
$processes = [];
try {
    foreach ([0, 1] as $index) {
        $process = new Process([PHP_BINARY, __FILE__, 'worker', $directory, (string) $index], dirname(__DIR__, 2));
        $process->setTimeout(40)->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 25;
    while (! file_exists($directory.'/ready-0') || ! file_exists($directory.'/ready-1')) {
        $check(microtime(true) < $deadline, 'Concurrent workers did not reach barrier');
        foreach ($processes as $process) {
            $check($process->isRunning(), 'Worker exited early: '.$process->getErrorOutput());
        }
        usleep(10000);
    }
    touch($directory.'/release');
    $results = [];
    foreach ($processes as $process) {
        $check($process->wait() === 0, 'Concurrent request failed: '.$process->getErrorOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    $check($results[0]['payment'] === $results[1]['payment'] && $results[0]['receipt'] === $results[1]['receipt'], 'Duplicate payment/receipt');
    $check($results[0]['replayed'] !== $results[1]['replayed'], 'Expected one application and one replay');
    // Simulate committed response lost: discard initial result and retry the same token.
    $retry = app(SecurePaymentService::class)->register($user, $company, $account->fresh(), $payload);
    $check($retry['replayed'] && $retry['payment']->id === $results[0]['payment'], 'Lost-response retry differs');
    $check(AccountReceivablePayment::count() === 1 && CobrosReceipt::count() === 1, 'Wrong counts');
    $check((string) $account->fresh()->balance_due === '2000.0000', 'Wrong balance');
    $payment = AccountReceivablePayment::sole();
    $check($payment->cash_session_id === null && ! $payment->affects_cash_snapshot && (string) $payment->cash_effect_amount === '0.0000', 'Cash affected');
    $check(DB::table('cash_sessions')->count() === 0 && DB::table('cash_movements')->count() === 0, 'Cash rows created');
    echo json_encode(['result' => 'PASS', 'database' => $directory.'/exclusive.sqlite', 'workers' => $results, 'lost_response_retry' => 'PASS (service simulation)', 'payments' => 1, 'receipts' => 1, 'balance' => '2000.0000', 'cash_rows' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop();
        }
    }
    // Preserve evidence and exclusive database; no delete/rollback of payments.
}
