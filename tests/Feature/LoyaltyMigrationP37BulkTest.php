<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\User;
use App\Services\Imports\LoyaltyMigrationImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyMigrationP37BulkTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_confirmation_processes_4057_customers_and_49_pending_without_n_plus_one(): void
    {
        $company = Company::create(['trade_name' => 'P37 volumen', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $now = now();
        $customers = [];
        for ($index = 1; $index <= 4057; $index++) {
            $customers[] = [
                'company_id' => $company->id,
                'customer_type' => 'individual',
                'name' => 'Carga masiva '.$index,
                'identification_type' => 'national',
                'identification' => 'MASIVA'.$index,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($customers, 500) as $chunk) {
            DB::table('customers')->insert($chunk);
        }

        $sourceKey = 'P37-SIMPLE-'.str_repeat('B', 40);
        $rows = Customer::query()->where('company_id', $company->id)->orderBy('id')->get(['id', 'name'])
            ->map(fn (Customer $customer, int $index) => $this->validRow($customer, $sourceKey, $index + 2))->all();
        for ($index = 0; $index < 49; $index++) {
            $rows[] = $this->pendingRow($sourceKey, $index);
        }
        $preview = ['company_id' => $company->id, 'source_key' => $sourceKey, 'rows' => $rows];
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $service = app(LoyaltyMigrationImportService::class);
        $this->assertSame(4057, $service->confirm($preview, $company->id, $user->id));
        $this->assertSame(0, $service->confirm($preview, $company->id, $user->id));

        $this->assertLessThan(170, $queries, 'La cantidad de queries debe crecer por bloques, no por cliente.');
        $this->assertDatabaseCount('loyalty_accounts', 4057);
        $this->assertDatabaseCount('loyalty_movements', 8114);
        $this->assertDatabaseCount('loyalty_migration_pending_rows', 49);
        $this->assertDatabaseCount('loyalty_migration_batches', 1);
        $sample = LoyaltyAccount::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame('8.1000', (string) $sample->balance);
        $this->assertSame('10.1255', (string) $sample->total_earned);
        $this->assertSame('2.0255', (string) $sample->total_redeemed);
    }

    private function validRow(Customer $customer, string $sourceKey, int $rowNumber): array
    {
        return [
            'row_number' => $rowNumber, 'source_key' => $sourceKey,
            'name' => $customer->name, 'normalized_name' => Str::lower($customer->name),
            'customer_id' => $customer->id, 'customer_candidates' => [],
            'awarded_points' => '10.1255', 'used_points' => '2.0255', 'balance' => '8.1000',
            'current_account_id' => null, 'current_balance' => null, 'current_movement_count' => 0,
            'consolidated_count' => 1, 'errors' => [], 'valid' => true,
        ];
    }

    private function pendingRow(string $sourceKey, int $index): array
    {
        return [
            'row_number' => 4059 + $index, 'source_key' => $sourceKey,
            'name' => 'Pendiente '.$index, 'normalized_name' => 'pendiente '.$index,
            'customer_id' => null, 'customer_candidates' => [],
            'awarded_points' => '1.0000', 'used_points' => '0.0000', 'balance' => '1.0000',
            'consolidated_count' => 1,
            'errors' => [['field' => 'nombre', 'message' => 'El cliente no existe en la empresa activa.']],
            'valid' => false,
        ];
    }
}
