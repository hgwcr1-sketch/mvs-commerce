<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMigrationRun;
use App\Models\LoyaltyMovement;
use App\Models\User;
use App\Services\Imports\RepairP37IncompatibleSnapshots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RepairP37IncompatibleSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_identifies_exact_cases_without_writes(): void
    {
        $fixture = $this->fixture();

        $this->artisan('loyalty:p37-repair-incompatible-snapshots', $fixture['options'] + ['--dry-run' => true])
            ->expectsOutputToContain('32 candidatos; 0 reparados')
            ->expectsOutputToContain('4089 importados / 17 pendientes')
            ->assertSuccessful();

        $this->assertDatabaseCount('loyalty_movements', 32);
        $this->assertDatabaseCount('loyalty_migration_pending_rows', 17);
        $this->assertSame(4089, (int) DB::table('loyalty_migration_batches')->value('row_count'));
    }

    public function test_apply_reverts_only_affected_rows_and_sets_production_counts(): void
    {
        $fixture = $this->fixture();

        $this->artisan('loyalty:p37-repair-incompatible-snapshots', $fixture['options'] + ['--apply' => true])
            ->expectsOutputToContain('32 candidatos; 32 reparados')
            ->expectsOutputToContain('4057 importados / 49 pendientes')
            ->assertSuccessful();

        $this->assertDatabaseCount('loyalty_movements', 0);
        $this->assertDatabaseCount('loyalty_accounts', 0);
        $this->assertDatabaseCount('loyalty_migration_pending_rows', 49);
        $this->assertDatabaseHas('loyalty_migration_batches', ['id' => $fixture['batch_id'], 'row_count' => 4057]);
        $this->assertDatabaseHas('loyalty_migration_runs', ['id' => $fixture['run_id'], 'imported_count' => 4057, 'pending_count' => 49]);
    }

    public function test_second_apply_is_idempotent(): void
    {
        $fixture = $this->fixture();
        $this->artisan('loyalty:p37-repair-incompatible-snapshots', $fixture['options'] + ['--apply' => true])->assertSuccessful();

        $this->artisan('loyalty:p37-repair-incompatible-snapshots', $fixture['options'] + ['--apply' => true])
            ->expectsOutputToContain('32 candidatos; 0 reparados')
            ->expectsOutputToContain('4057 importados / 49 pendientes')
            ->assertSuccessful();

        $this->assertDatabaseCount('loyalty_migration_pending_rows', 49);
        $this->assertDatabaseHas('loyalty_migration_batches', ['id' => $fixture['batch_id'], 'row_count' => 4057]);
    }

    public function test_apply_rolls_back_everything_when_one_account_has_a_later_movement(): void
    {
        $fixture = $this->fixture(true);

        try {
            app(RepairP37IncompatibleSnapshots::class)->apply(
                $fixture['company_id'],
                $fixture['source_key'],
                $fixture['batch_id'],
            );
            $this->fail('La reparación debía bloquearse por el movimiento posterior.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('movimientos posteriores', $exception->errors()['repair'][0]);
        }

        $this->assertDatabaseCount('loyalty_accounts', 32);
        $this->assertDatabaseCount('loyalty_movements', 33);
        $this->assertDatabaseCount('loyalty_migration_pending_rows', 17);
        $this->assertDatabaseHas('loyalty_migration_batches', ['id' => $fixture['batch_id'], 'row_count' => 4089]);
        $this->assertDatabaseHas('loyalty_migration_runs', ['id' => $fixture['run_id'], 'imported_count' => 4089, 'pending_count' => 17]);
    }

    private function fixture(bool $withLaterMovement = false): array
    {
        $company = Company::create(['trade_name' => 'P37 reparación', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $sourceKey = 'P37-SIMPLE-'.str_repeat('D', 40);
        $batchId = DB::table('loyalty_migration_batches')->insertGetId([
            'company_id' => $company->id, 'user_id' => $user->id, 'source_key' => $sourceKey,
            'row_count' => 4089, 'imported_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sourceRows = [];
        $rows = [];
        $accountWithLaterMovement = null;
        for ($index = 0; $index < 32; $index++) {
            $customer = Customer::create([
                'company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Snapshot '.$index,
                'identification_type' => 'national', 'identification' => 'REPAIR'.$index, 'is_active' => true,
            ]);
            $rowNumber = 2 + ($index * 2);
            $sourceRows[] = $this->sourceRow($customer, $sourceKey, $rowNumber, '10.0000');
            $sourceRows[] = $this->sourceRow($customer, $sourceKey, $rowNumber + 1, '20.0000');
            $rows[] = $this->incompatibleRow($customer, $sourceKey, $rowNumber);
            $account = LoyaltyAccount::create([
                'company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => '10.0000',
                'total_earned' => '0.0000', 'total_redeemed' => '0.0000', 'total_expired' => '0.0000',
            ]);
            $accountWithLaterMovement = $account;
            LoyaltyMovement::create($this->movement($company->id, $user->id, $customer->id, $account->id, $batchId, $sourceKey, $rowNumber));
        }
        for ($index = 0; $index < 17; $index++) {
            DB::table('loyalty_migration_pending_rows')->insert([
                'batch_id' => $batchId, 'company_id' => $company->id, 'source_key' => $sourceKey,
                'row_number' => 1000 + $index, 'source_rows' => '[]', 'source_data' => '{}', 'reasons' => '[]',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $run = LoyaltyMigrationRun::create([
            'company_id' => $company->id, 'user_id' => $user->id, 'source_key' => $sourceKey,
            'status' => LoyaltyMigrationRun::STATUS_COMPLETED,
            'preview_payload' => ['company_id' => $company->id, 'source_key' => $sourceKey, 'source_rows' => $sourceRows, 'rows' => $rows],
            'valid_count' => 4089, 'pending_count' => 17, 'imported_count' => 4089,
            'consolidated_count' => 32, 'attempts' => 1, 'queued_at' => now(), 'completed_at' => now(),
        ]);
        if ($withLaterMovement) {
            LoyaltyMovement::create($this->movement(
                $company->id, $user->id, $accountWithLaterMovement->customer_id, $accountWithLaterMovement->id, $batchId + 100,
                'POSTERIOR', 9999,
            ) + ['event_key' => 'posterior:operativo', 'source_type' => 'Sale', 'points' => '1.0000', 'balance_before' => '10.0000', 'balance_after' => '11.0000']);
        }

        return [
            'company_id' => $company->id, 'source_key' => $sourceKey, 'batch_id' => $batchId, 'run_id' => $run->id,
            'options' => ['--company-id' => $company->id, '--source-key' => $sourceKey, '--batch-id' => $batchId],
        ];
    }

    private function sourceRow(Customer $customer, string $sourceKey, int $rowNumber, string $balance): array
    {
        return [
            'row_number' => $rowNumber, 'source_key' => $sourceKey, 'name' => $customer->name,
            'normalized_name' => strtolower($customer->name), 'customer_id' => $customer->id,
            'awarded_points' => '0.0000', 'used_points' => '0.0000', 'balance' => $balance,
        ];
    }

    private function incompatibleRow(Customer $customer, string $sourceKey, int $rowNumber): array
    {
        return $this->sourceRow($customer, $sourceKey, $rowNumber, '10.0000') + [
            'source_row_numbers' => [$rowNumber, $rowNumber + 1], 'consolidated_count' => 2,
            'consolidation_method' => 'incompatible', 'valid' => false, 'current_account_id' => null,
            'errors' => [['field' => 'saldo', 'message' => 'Los snapshots legacy repetidos tienen saldos finales distintos; no es seguro sumarlos.']],
        ];
    }

    private function movement(int $companyId, int $userId, int $customerId, int $accountId, int $batchId, string $sourceKey, int $rowNumber): array
    {
        return [
            'company_id' => $companyId, 'loyalty_account_id' => $accountId, 'customer_id' => $customerId,
            'user_id' => $userId, 'type' => LoyaltyMovement::TYPE_ADJUSTMENT, 'points' => '10.0000',
            'balance_before' => '0.0000', 'balance_after' => '10.0000', 'description' => 'P37',
            'source_type' => 'LoyaltyMigration', 'source_id' => $batchId,
            'event_key' => "loyalty_migration:{$sourceKey}:{$rowNumber}:legacy_initial_balance",
            'effective_at' => now(), 'metadata' => ['migration' => 'P37', 'kind' => 'legacy_initial_balance'],
        ];
    }
}
