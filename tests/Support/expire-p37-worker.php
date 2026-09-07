<?php

use App\Models\Company;
use App\Models\LoyaltyAccount;
use App\Services\Loyalty\LoyaltyExpirationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Proceso auxiliar exclusivo de la prueba de concurrencia PostgreSQL local.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = config('database.connections.pgsql');
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || ! in_array($connection['host'], ['127.0.0.1', 'localhost'], true)
    || ! str_ends_with($connection['database'], '_test')) {
    throw new RuntimeException('Solo se permite PostgreSQL local de pruebas.');
}
$company = Company::findOrFail($argv[1]);
$account = LoyaltyAccount::findOrFail($argv[2]);
echo DB::selectOne('select pg_backend_pid() as pid')->pid.PHP_EOL;
flush();
$movement = app(LoyaltyExpirationService::class)
    ->expireAccount($company, $account, '2026-10-02');
echo json_encode(['expired' => $movement !== null]).PHP_EOL;
