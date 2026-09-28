<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyPortalCredential;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class P08SecurityPostgresCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_and_authentication_throttles_are_active(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post(route('login.store'), ['email' => 'nobody@example.test', 'password' => 'Wrong123']);
        }
        $this->post(route('login.store'), ['email' => 'nobody@example.test', 'password' => 'Wrong123'])->assertTooManyRequests();
    }

    public function test_portal_limits_credentials_and_keeps_company_isolation(): void
    {
        [$company, $customer] = $this->portalContext('Uno');
        [$otherCompany] = $this->portalContext('Dos');
        LoyaltyPortalCredential::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'username' => 'cliente-seguro',
            'email' => 'cliente@example.test',
            'password' => Hash::make('PortalSeguro9'),
            'is_active' => true,
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('loyalty.customer.login.store', $company), ['username' => 'cliente-seguro', 'password' => 'incorrecta']);
        }
        $this->post(route('loyalty.customer.login.store', $company), ['username' => 'cliente-seguro', 'password' => 'PortalSeguro9'])
            ->assertSessionHasErrors('username');

        $this->withSession(['loyalty_portal_company_id' => $company->id, 'loyalty_portal_customer_id' => $customer->id])
            ->get(route('loyalty.customer.home', $otherCompany))->assertForbidden();
    }

    public function test_portal_capacity_baseline_uses_scoped_indexes_with_thousands_of_customers(): void
    {
        [$company, $customer, $branch] = $this->portalContext('Carga');
        $now = now();
        foreach (array_chunk(range(1, 3000), 500) as $chunk) {
            DB::table('customers')->insert(array_map(fn (int $number) => [
                'company_id' => $company->id,
                'customer_type' => 'individual',
                'name' => 'Cliente carga '.$number,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
        $account = LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => 500, 'is_active' => true]);
        $staffId = $this->staffId();
        for ($number = 1; $number <= 100; $number++) {
            LoyaltyMovement::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'loyalty_account_id' => $account->id, 'customer_id' => $customer->id, 'type' => 'purchase', 'points' => 5, 'balance_before' => 0, 'balance_after' => 5, 'description' => 'Carga', 'event_key' => 'p08-'.$number, 'effective_at' => $now]);
            Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $staffId, 'customer_id' => $customer->id, 'sale_number' => 'P08-'.$number, 'document_type' => 'electronic_ticket', 'status' => Sale::STATUS_COMPLETED, 'total' => 100, 'paid_total' => 100, 'completed_at' => $now]);
        }

        $started = hrtime(true);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        Customer::query()->where('company_id', $company->id)->whereKey($customer->id)->firstOrFail();
        LoyaltyAccount::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->firstOrFail();
        LoyaltyMovement::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->latest('effective_at')->limit(15)->get();
        Sale::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->where('status', Sale::STATUS_COMPLETED)->latest('completed_at')->limit(8)->get();
        $elapsedMs = round((hrtime(true) - $started) / 1_000_000, 2);

        fwrite(STDERR, json_encode(['p08_capacity' => ['customers' => 3001, 'queries' => $queries, 'elapsed_ms' => $elapsedMs]], JSON_THROW_ON_ERROR).PHP_EOL);
        $this->assertSame(4, $queries);
        $this->assertLessThan(2000, $elapsedMs);
        $this->assertContains('sales_portal_history_index', array_column(Schema::getIndexes('sales'), 'name'));
        $this->assertContains('loyalty_movements_portal_index', array_column(Schema::getIndexes('loyalty_movements'), 'name'));
    }

    private function portalContext(string $suffix): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.$suffix, 'legal_name' => 'Empresa '.$suffix, 'identification_number' => uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Central', 'code' => 'C'.uniqid(), 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente '.$suffix, 'is_active' => true]);

        return [$company, $customer, $branch];
    }

    private function staffId(): int
    {
        return User::factory()->create()->id;
    }
}
