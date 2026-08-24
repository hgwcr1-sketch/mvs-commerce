<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyMultiplier;
use App\Models\LoyaltySetting;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\Loyalty\LoyaltyOnlineSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyOnlineSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_online_sale_accrues_points_with_percentage_and_audited_origin(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company, '5.0000');
        $customer = $this->customer($company);
        $sale = $this->onlineSale($company, $branch, $customer, '1000.0000');

        $result = $this->service()->accrueForSale($sale, $customer, 'ORDER-1001');

        $this->assertTrue($result['earned']);
        $this->assertFalse($result['duplicate']);
        $this->assertSame('50.0000', $result['points']);

        // Misma cuenta central por empresa; sin cuenta web paralela.
        $account = LoyaltyAccount::query()->where('company_id', $company->id)->sole();
        $this->assertSame('50.0000', $account->balance);

        // Origen online auditado: tipo, fuente y metadata del canal.
        $movement = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_PURCHASE)->sole();
        $this->assertSame(Sale::class, $movement->source_type);
        $this->assertSame($sale->id, (int) $movement->source_id);
        $this->assertSame('online_sale:online:ORDER-1001:loyalty:earn', $movement->event_key);
        $this->assertSame('online', $movement->metadata['channel']);
        $this->assertSame('online', $movement->metadata['origin']);
        $this->assertSame('ORDER-1001', $movement->metadata['external_reference']);
        $this->assertSame('5.0000', $movement->earning_percentage);
        $this->assertSame('1000.0000', $movement->base_amount);
    }

    public function test_online_purchase_shares_the_same_loyalty_account_as_previous_pos_purchase(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company, '5.0000');
        $customer = $this->customer($company);

        // Compra POS previa: acumulación por el servicio central usado por el POS.
        $account = app(LoyaltyAccountService::class)->getOrCreateAccount($customer, $company);
        app(LoyaltyAccountService::class)->addPoints($account, '120.0000', LoyaltyMovement::TYPE_PURCHASE, [
            'event_key' => 'sale:1:loyalty:earn',
            'description' => 'Puntos por venta POS-1',
        ]);

        $sale = $this->onlineSale($company, $branch, $customer, '2000.0000');
        $this->service()->accrueForSale($sale, $customer, 'ORDER-2002');

        $this->assertSame(1, LoyaltyAccount::query()->where('customer_id', $customer->id)->count());
        $this->assertSame('220.0000', $account->fresh()->balance);
    }

    public function test_offer_eligibility_rule_is_respected(): void
    {
        [$company, $branch] = $this->companyContext();
        $customer = $this->customer($company);
        $lines = [
            ['subtotal' => '800.0000', 'is_offer' => false],
            ['subtotal' => '200.0000', 'is_offer' => true],
        ];

        // F13 desactivado: la parte en oferta no es elegible.
        $setting = $this->setting($company, '10.0000', earnOnOffers: false);
        $sale = $this->onlineSale($company, $branch, $customer, '0', $lines);
        $this->service()->accrueForSale($sale, $customer, 'ORDER-3003');
        $account = LoyaltyAccount::query()->where('company_id', $company->id)->sole();
        $this->assertSame('80.0000', $account->balance);

        // F13 activado: también lo ofrecido es elegible.
        $setting->update(['earn_on_offers' => true]);
        $sale2 = $this->onlineSale($company, $branch, $customer, '0', $lines);
        $this->service()->accrueForSale($sale2, $customer, 'ORDER-3004');
        $this->assertSame('180.0000', $account->fresh()->balance);
    }

    public function test_multiplier_rule_is_reused(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company, '5.0000');
        LoyaltyMultiplier::create([
            'company_id' => $company->id,
            'name' => 'Doble puntos web',
            'multiplier' => '2.0000',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $customer = $this->customer($company);
        $sale = $this->onlineSale($company, $branch, $customer, '1000.0000');

        $result = $this->service()->accrueForSale($sale, $customer, 'ORDER-4005');

        $this->assertSame('100.0000', $result['points']);
        $movement = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_PURCHASE)->sole();
        $this->assertSame('2.0000', $movement->metadata['multiplier']);
        $this->assertNotNull($movement->metadata['multiplier_id']);
    }

    public function test_duplicated_online_event_never_duplicates_points(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company, '5.0000');
        $customer = $this->customer($company);
        $sale = $this->onlineSale($company, $branch, $customer, '1000.0000');
        $service = $this->service();

        $first = $service->accrueForSale($sale, $customer, 'ORDER-5006');
        $second = $service->accrueForSale($sale, $customer, 'ORDER-5006');

        $this->assertTrue($first['earned']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, LoyaltyMovement::query()->where('event_key', 'like', 'online_sale:%')->count());

        $account = LoyaltyAccount::query()->where('company_id', $company->id)->sole();
        $this->assertSame('50.0000', $account->balance);
        $this->assertSame('50.0000', $account->total_earned);

        // Una referencia distinta del mismo pedido/canal sí es un evento nuevo.
        $third = $service->accrueForSale($sale, $customer, 'ORDER-5007');
        $this->assertTrue($third['earned']);
        $this->assertSame('100.0000', $account->fresh()->balance);
    }

    public function test_foreign_customer_branch_or_company_are_blocked(): void
    {
        [$companyA, $branchA] = $this->companyContext();
        [$companyB, $branchB] = $this->companyContext();
        $this->setting($companyA, '5.0000');
        $foreignCustomer = $this->customer($companyB);
        $sale = $this->onlineSale($companyA, $branchA, null, '1000.0000');

        // Cliente de otra empresa: bloqueado por el servicio central de acumulación.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('El cliente no pertenece a la empresa.');
        $this->service()->accrueForSale($sale, $foreignCustomer, 'ORDER-6008');
    }

    public function test_sale_of_inactive_company_is_blocked(): void
    {
        [$companyA] = $this->companyContext();
        [$companyB, $branchB] = $this->companyContext();
        $companyB->update(['is_active' => false]);
        $this->setting($companyA, '5.0000');
        $customer = $this->customer($companyA);
        $sale = $this->onlineSale($companyB, $branchB, null, '1000.0000');

        try {
            $this->service()->accrueForSale($sale, $customer, 'ORDER-6009');
            $this->fail('Se esperaba ValidationException por empresa inactiva.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company', $exception->errors());
        }
        $this->assertSame(0, LoyaltyMovement::count());
    }

    public function test_unconfirmed_sale_is_rejected(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company, '5.0000');
        $customer = $this->customer($company);
        $sale = $this->onlineSale($company, $branch, null, '1000.0000', status: Sale::STATUS_DRAFT);

        try {
            $this->service()->accrueForSale($sale, $customer, 'ORDER-6010');
            $this->fail('Se esperaba ValidationException por venta no confirmada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sale', $exception->errors());
        }
        $this->assertSame(0, LoyaltyMovement::count());
    }

    public function test_sale_without_customer_follows_current_behavior(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company, '5.0000');
        $sale = $this->onlineSale($company, $branch, null, '1000.0000');

        $result = $this->service()->accrueForSale($sale, null, 'ORDER-7011');

        $this->assertFalse($result['earned']);
        $this->assertSame('0.0000', $result['points']);
        $this->assertSame(0, LoyaltyAccount::count());
        $this->assertSame(0, LoyaltyMovement::count());
    }

    public function test_accrual_touches_no_inventory(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company, '5.0000');
        $customer = $this->customer($company);
        $sale = $this->onlineSale($company, $branch, $customer, '1000.0000');

        $result = $this->service()->accrueForSale($sale, $customer, 'ORDER-8012');

        $this->assertTrue($result['earned']);

        // F36 solo acredita puntos: ningún movimiento de inventario ni cambio de existencias.
        $this->assertSame(0, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('branch_product')->count());
    }

    public function test_birthday_bonus_flows_through_the_same_pipeline(): void
    {
        [$company, $branch] = $this->companyContext();
        $setting = $this->setting($company, '5.0000');
        $setting->update(['birthday_enabled' => true, 'birthday_points' => '100.0000']);
        $customer = $this->customer($company, ['birth_date' => today()->toDateString()]);
        $sale = $this->onlineSale($company, $branch, $customer, '1000.0000');

        $this->service()->accrueForSale($sale, $customer, 'ORDER-9013');

        $eventKeys = LoyaltyMovement::query()->pluck('event_key');
        $types = LoyaltyMovement::query()->pluck('type');

        // El bono de cumpleaños fluye por el mismo pipeline (F10) junto al earn (F08).
        $this->assertTrue($eventKeys->contains(fn ($key) => str_starts_with((string) $key, 'birthday:')));
        $this->assertContains(LoyaltyMovement::TYPE_PURCHASE, $types);
        $this->assertContains(LoyaltyMovement::TYPE_BIRTHDAY, $types);
    }

    private function service(): LoyaltyOnlineSaleService
    {
        return app(LoyaltyOnlineSaleService::class);
    }

    private function companyContext(): array
    {
        $company = Company::create(['trade_name' => 'Online '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);

        return [$company, $branch];
    }

    private function setting(Company $company, string $percentage, bool $earnOnOffers = true): LoyaltySetting
    {
        return LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => $percentage,
            'point_value' => '1.0000',
            'earn_on_offers' => $earnOnOffers,
        ]);
    }

    private function customer(Company $company, array $extra = []): Customer
    {
        return Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'CLIENTE-WEB', 'credit_limit' => 0, 'is_active' => true] + $extra);
    }

    /**
     * @param  array<int, array{subtotal:string,is_offer:bool,quantity?:string,unit_price?:string}>|null  $items
     */
    private function onlineSale(Company $company, Branch $branch, ?Customer $customer, string $subtotal, ?array $items = null, string $status = Sale::STATUS_COMPLETED): Sale
    {
        $lines = $items ?? [['subtotal' => $subtotal, 'is_offer' => false]];
        $total = array_sum(array_map(fn ($line) => (float) $line['subtotal'], $lines));

        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['is_active' => true])->id,
            'cash_session_id' => null,
            'customer_id' => $customer?->id,
            'checkout_token' => 'web-'.uniqid(),
            'request_fingerprint' => hash('sha256', uniqid('', true)),
            'sale_number' => 'WEB-'.strtoupper(substr(uniqid(), -6)),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => $status,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => $total,
            'discount_total' => 0,
            'tax_total' => 0,
            'rounding_total' => 0,
            'total' => $total,
            'paid_total' => $status === Sale::STATUS_COMPLETED ? $total : 0,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);

        foreach ($lines as $index => $line) {
            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => null,
                'description' => 'Linea '.($index + 1),
                'quantity' => $line['quantity'] ?? '1.0000',
                'unit_price' => $line['unit_price'] ?? $line['subtotal'],
                'gross_total' => $line['subtotal'],
                'discount_total' => 0,
                'subtotal' => $line['subtotal'],
                'tax_rate' => 0,
                'tax_total' => 0,
                'total' => $line['subtotal'],
                'unit_cost' => 0,
                'is_offer' => $line['is_offer'],
            ]);
        }

        return $sale;
    }
}
