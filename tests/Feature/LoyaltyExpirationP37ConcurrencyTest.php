<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Services\Loyalty\LoyaltyEarningService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LoyaltyExpirationP37ConcurrencyTest extends TestCase
{
    public function test_expiration_waits_for_purchase_commit_and_rechecks_reference_under_lock(): void
    {
        $connection = config('database.connections.pgsql');
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL local con bloqueo real de filas.');
        }
        $this->assertContains($connection['host'], ['127.0.0.1', 'localhost']);
        $this->assertStringEndsWith('_test', $connection['database']);
        $this->assertTrue(app()->environment('testing'));
        // Base exclusiva de tests: fixtures deben estar confirmados para el segundo proceso.
        Artisan::call('migrate:fresh', ['--force' => true]);
        $company = Company::create(['trade_name' => 'P37 concurrente', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'expiration_enabled' => true, 'expiration_months' => 1, 'earning_percentage' => '5.0000', 'point_value' => '1.0000']);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente concurrente', 'customer_type' => 'individual', 'is_active' => true]);
        $account = LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => '97.0000', 'is_active' => true]);
        LoyaltyMovement::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'loyalty_account_id' => $account->id, 'type' => 'adjustment', 'points' => '97.0000', 'balance_before' => '0.0000', 'balance_after' => '97.0000', 'description' => 'P37 legado', 'source_type' => 'LoyaltyMigration', 'effective_at' => '2026-09-02 12:00:00', 'metadata' => ['migration' => 'P37', 'kind' => 'legacy_initial_balance']]);
        $args = [PHP_BINARY];
        // Propagar extensión habilitada solo en CLI, sin modificar php.ini.
        if (PHP_OS_FAMILY === 'Windows') {
            $args = array_merge($args, ['-d', 'extension=php_pdo_pgsql.dll']);
        }
        $args = array_merge($args, [base_path('tests/Support/expire-p37-worker.php'), (string) $company->id, (string) $account->id]);
        $process = new Process($args, base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'] ?? '', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
        ]);
        $process->setTimeout(20);
        DB::beginTransaction();
        try {
            LoyaltyAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $waiting = false;
            $deadline = microtime(true) + 15;
            while (microtime(true) < $deadline && $process->isRunning()) {
                $pid = (int) trim(explode("\n", $process->getOutput())[0]);
                if ($pid > 0) {
                    $status = DB::selectOne('select wait_event_type from pg_stat_activity where pid = ?', [$pid]);
                    if ($status?->wait_event_type === 'Lock') {
                        $waiting = true;
                        break;
                    }
                }
                usleep(20000);
            }
            $this->assertTrue($waiting, 'El proceso debe esperar el lock: '.$process->getErrorOutput());
            app(LoyaltyEarningService::class)->earnFromEligibleAmount($customer, $company, '1000.0000', ['effective_at' => '2026-10-01 12:00:00']);
            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringContainsString('"expired":false', $process->getOutput());
            $this->assertSame('147.0000', $account->fresh()->balance);
            $this->assertSame(0, LoyaltyMovement::where('type', 'expiration')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
            Artisan::call('migrate:rollback', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        }
    }
}
