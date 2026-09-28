<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\Loyalty\LoyaltyOnlineSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyOnlineRedemptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_online_redemption_discounts_balance_and_registers_coherent_payment(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company);
        $this->pointsMethod($company);
        $customer = $this->customerWithPoints($company, '500.0000');
        $sale = $this->onlineSale($company, $branch, $customer, '10000.0000');

        $result = $this->service()->redeemForSale($sale, $customer, '300', 'ORDER-R1001');

        $this->assertTrue($result['redeemed']);
        $this->assertFalse($result['duplicate']);
        $this->assertSame('300.0000', $result['redeemed_points']);
        $this->assertSame('300.0000', $result['redeemed_amount']);
        $this->assertSame('200.0000', $result['balance_after']);
        $this->assertSame('200.0000', LoyaltyAccount::query()->where('company_id', $company->id)->sole()->balance);

        // Pago con puntos representado como en POS: método de puntos, sin efecto de caja.
        $payment = SalePayment::query()->where('sale_id', $sale->id)->sole();
        $this->assertSame(PaymentMethod::TYPE_LOYALTY_POINTS, $payment->paymentMethod->type);
        $this->assertSame('300.0000', (string) $payment->amount);
        $this->assertFalse((bool) $payment->affects_cash_snapshot);
        $this->assertSame(0, (int) $payment->cash_effect_amount);
        $this->assertSame(SalePayment::STATUS_COMPLETED, $payment->status);

        // Movimiento de canje auditado con origen online y event_key propio del canje.
        $movement = LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_REDEMPTION)->sole();
        $this->assertSame('-300.0000', (string) $movement->points);
        $this->assertSame('online_sale:online:ORDER-R1001:loyalty:redemption', $movement->event_key);
        $this->assertSame('online', $movement->metadata['channel']);
        $this->assertSame('ORDER-R1001', $movement->metadata['external_reference']);
        $this->assertSame($sale->id, (int) $movement->source_id);
    }

    public function test_redemption_shares_the_central_account_with_pos_history(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company);
        $this->pointsMethod($company);
        $customer = $this->customerWithPoints($company, '800.0000');
        $sale = $this->onlineSale($company, $branch, $customer, '5000.0000');

        $this->service()->redeemForSale($sale, $customer, '250', 'ORDER-R2002');

        // Una única cuenta central para POS y online; el saldo refleja ambos mundos.
        $this->assertSame(1, LoyaltyAccount::query()->where('customer_id', $customer->id)->count());
        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('550.0000', $account->balance);
        $this->assertSame('800.0000', $account->total_earned);
        $this->assertSame('250.0000', $account->total_redeemed);
    }

    public function test_minimum_redemption_rule_is_respected(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company, minimumEnabled: true, minimumAmount: '1000.0000');
        $customer = $this->customerWithPoints($company, '500.0000');
        $sale = $this->onlineSale($company, $branch, $customer, '10000.0000');

        try {
            $this->service()->redeemForSale($sale, $customer, '100', 'ORDER-R3003');
            $this->fail('Se esperaba ValidationException por mínimo de canje.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('redemption', $exception->errors());
        }
        $this->assertSame('500.0000', LoyaltyAccount::query()->where('company_id', $company->id)->sole()->balance);
        $this->assertSame(0, SalePayment::count());
    }

    public function test_maximum_redemption_rule_is_respected(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company, maximumPercent: '30.00');
        $this->pointsMethod($company);
        $customer = $this->customerWithPoints($company, '9000.0000');
        $sale = $this->onlineSale($company, $branch, $customer, '10000.0000');

        // Máximo pagable con puntos: 30% de ₡10.000 = ₡3.000.
        try {
            $this->service()->redeemForSale($sale, $customer, '4000', 'ORDER-R4004');
            $this->fail('Se esperaba ValidationException por máximo pagable con puntos.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('requested_points', $exception->errors());
        }
        $this->assertSame('9000.0000', LoyaltyAccount::query()->where('company_id', $company->id)->sole()->balance);

        // Dentro del límite sí se permite.
        $result = $this->service()->redeemForSale($sale, $customer, '3000', 'ORDER-R4005');
        $this->assertTrue($result['redeemed']);
        $this->assertSame('6000.0000', $result['balance_after']);
    }

    public function test_offers_rule_is_respected(): void
    {
        [$company, $branch] = $this->companyContext();
        $setting = $this->setting($company);
        $this->pointsMethod($company);
        $customer = $this->customerWithPoints($company, '500.0000');
        $sale = $this->onlineSale($company, $branch, $customer, '5000.0000', [
            ['subtotal' => '5000.0000', 'is_offer' => true],
        ]);

        // F17 desactivado: no se puede canjear sobre ofertas.
        try {
            $this->service()->redeemForSale($sale, $customer, '100', 'ORDER-R5006');
            $this->fail('Se esperaba ValidationException por canje sobre ofertas.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_offer', $exception->errors());
        }

        // F17 activado: permitido.
        $setting->update(['redeem_on_offers' => true]);
        $result = $this->service()->redeemForSale($sale, $customer, '100', 'ORDER-R5007');
        $this->assertTrue($result['redeemed']);
        $this->assertSame('400.0000', $result['balance_after']);
    }

    public function test_insufficient_balance_blocks_redemption(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company);
        $customer = $this->customerWithPoints($company, '50.0000');
        $sale = $this->onlineSale($company, $branch, $customer, '50000.0000');

        try {
            $this->service()->redeemForSale($sale, $customer, '500', 'ORDER-R6008');
            $this->fail('Se esperaba ValidationException por saldo insuficiente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('requested_points', $exception->errors());
        }
        $this->assertSame(0, SalePayment::count());
        $this->assertSame(0, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_REDEMPTION)->count());
    }

    public function test_customer_is_required_and_foreign_customer_is_blocked(): void
    {
        [$companyA, $branchA] = $this->companyContext();
        [$companyB] = $this->companyContext();
        $this->setting($companyA);
        $foreignCustomer = $this->customerWithPoints($companyB, '500.0000');
        $sale = $this->onlineSale($companyA, $branchA, null, '5000.0000');

        // Sin cliente no hay canje.
        try {
            $this->service()->redeemForSale($sale, null, '100', 'ORDER-R7009');
            $this->fail('Se esperaba ValidationException por cliente ausente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }

        // Cliente de otra empresa: bloqueado por la validación central.
        try {
            $this->service()->redeemForSale($sale, $foreignCustomer, '100', 'ORDER-R7010');
            $this->fail('Se esperaba ValidationException por cliente ajeno.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer', $exception->errors());
        }

        $this->assertSame(0, SalePayment::count());
        $this->assertSame(0, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_REDEMPTION)->count());
    }

    public function test_unconfirmed_sale_cannot_redeem(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company);
        $customer = $this->customerWithPoints($company, '500.0000');
        $sale = $this->onlineSale($company, $branch, $customer, '5000.0000', status: Sale::STATUS_DRAFT);

        try {
            $this->service()->redeemForSale($sale, $customer, '100', 'ORDER-R8011');
            $this->fail('Se esperaba ValidationException por venta no confirmada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sale', $exception->errors());
        }
        $this->assertSame(0, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_REDEMPTION)->count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_duplicated_event_never_duplicates_payment_or_movement(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company);
        $this->pointsMethod($company);
        $customer = $this->customerWithPoints($company, '500.0000');
        $sale = $this->onlineSale($company, $branch, $customer, '5000.0000');
        $service = $this->service();

        $first = $service->redeemForSale($sale, $customer, '200', 'ORDER-R9012');
        $second = $service->redeemForSale($sale, $customer, '200', 'ORDER-R9012');

        $this->assertTrue($first['redeemed']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['redeemed_amount'], $second['redeemed_amount']);
        $this->assertSame(1, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_REDEMPTION)->count());
        $this->assertSame(1, SalePayment::count());

        // La cuenta no cambió entre el primer y el segundo intento.
        $this->assertSame('300.0000', $first['balance_after']);
        $this->assertSame('300.0000', $second['balance_after']);
    }

    public function test_failure_after_redemption_rolls_back_completely(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company);
        $this->pointsMethod($company);
        $customer = $this->customerWithPoints($company, '500.0000');
        $sale = $this->onlineSale($company, $branch, $customer, '5000.0000');

        // Fallo inyectado al crear el pago: el movimiento ya se habría descontado,
        // así que la transacción debe revertir TODO (sin puntos descontados sin pago).
        SalePayment::creating(function () {
            throw new \RuntimeException('fallo inyectado al registrar el pago');
        });

        try {
            $this->service()->redeemForSale($sale, $customer, '200', 'ORDER-R10013');
            $this->fail('Se esperaba el fallo inyectado.');
        } catch (\RuntimeException) {
        }

        $account = LoyaltyAccount::query()->where('company_id', $company->id)->sole();
        $this->assertSame('500.0000', $account->balance);
        $this->assertSame('0.0000', $account->total_redeemed);
        $this->assertSame(0, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_REDEMPTION)->count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_missing_points_payment_method_blocks_without_side_effects(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company);
        $customer = $this->customerWithPoints($company, '500.0000');
        $sale = $this->onlineSale($company, $branch, $customer, '5000.0000');

        try {
            $this->service()->redeemForSale($sale, $customer, '100', 'ORDER-R11014');
            $this->fail('Se esperaba ValidationException por método de pago de puntos ausente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payments', $exception->errors());
        }
        $this->assertSame('500.0000', LoyaltyAccount::query()->where('company_id', $company->id)->sole()->balance);
        $this->assertSame(0, LoyaltyMovement::query()->where('type', LoyaltyMovement::TYPE_REDEMPTION)->count());
        $this->assertSame(0, SalePayment::count());
    }

    public function test_accrual_still_works_alongside_redemption(): void
    {
        [$company, $branch] = $this->companyContext();
        $this->setting($company, '5.0000');
        $this->pointsMethod($company);
        $customer = $this->customerWithPoints($company, '100.0000');
        $sale = $this->onlineSale($company, $branch, $customer, '10000.0000');
        $service = $this->service();

        $earned = $service->accrueForSale($sale, $customer, 'ORDER-R12015');
        $redeemed = $service->redeemForSale($sale, $customer, '300', 'ORDER-R12016');

        $this->assertTrue($earned['earned']);
        $this->assertSame('500.0000', $earned['points']);

        // Saldo: 100 iniciales + 500 ganados − 300 canjeados.
        $this->assertTrue($redeemed['redeemed']);
        $this->assertSame('300.0000', $redeemed['balance_after']);

        // Event keys independientes: earn y redemption no interfieren.
        $keys = LoyaltyMovement::query()->pluck('event_key')->sort()->values()->toArray();
        $this->assertContains('online_sale:online:ORDER-R12015:loyalty:earn', $keys);
        $this->assertContains('online_sale:online:ORDER-R12016:loyalty:redemption', $keys);
    }

    private function service(): LoyaltyOnlineSaleService
    {
        return app(LoyaltyOnlineSaleService::class);
    }

    private function pointsMethod(Company $company): PaymentMethod
    {
        return PaymentMethod::create([
            'company_id' => $company->id,
            'code' => 'puntos-'.substr(uniqid(), -5),
            'name' => 'Puntos de fidelidad',
            'type' => PaymentMethod::TYPE_LOYALTY_POINTS,
            'is_active' => true,
            'affects_cash' => false,
        ]);
    }

    private function companyContext(): array
    {
        $company = Company::create(['trade_name' => 'Reden '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);

        return [$company, $branch];
    }

    private function setting(Company $company, string $percentage = '5.0000', bool $minimumEnabled = false, string $minimumAmount = '0.0000', string $maximumPercent = '100.00'): LoyaltySetting
    {
        return LoyaltySetting::create([
            'company_id' => $company->id,
            'is_active' => true,
            'earning_percentage' => $percentage,
            'point_value' => '1.0000',
            'redemption_minimum_enabled' => $minimumEnabled,
            'redemption_minimum_amount' => $minimumAmount,
            'maximum_redemption_percent' => $maximumPercent,
        ]);
    }

    private function customerWithPoints(Company $company, string $points): Customer
    {
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'CLIENTE-WEB-REDEN', 'credit_limit' => 0, 'is_active' => true]);
        $account = app(LoyaltyAccountService::class)->getOrCreateAccount($customer, $company);
        app(LoyaltyAccountService::class)->addPoints($account, $points, LoyaltyMovement::TYPE_PURCHASE, ['description' => 'Saldo inicial de prueba']);

        return $customer;
    }

    /**
     * @param  array<int, array{subtotal:string,is_offer:bool}>|null  $items
     */
    private function onlineSale(Company $company, Branch $branch, ?Customer $customer, string $total, ?array $items = null, string $status = Sale::STATUS_COMPLETED): Sale
    {
        $lines = $items ?? [['subtotal' => $total, 'is_offer' => false]];

        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['is_active' => true])->id,
            'cash_session_id' => null,
            'customer_id' => $customer?->id,
            'checkout_token' => 'web-'.uniqid(),
            'request_fingerprint' => hash('sha256', uniqid('', true)),
            'sale_number' => 'WEBR-'.strtoupper(substr(uniqid(), -6)),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => $status,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => array_sum(array_map(fn ($line) => (float) $line['subtotal'], $lines)),
            'discount_total' => 0,
            'tax_total' => 0,
            'rounding_total' => 0,
            'total' => $total,
            'paid_total' => 0,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);

        foreach ($lines as $index => $line) {
            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => null,
                'description' => 'Linea '.($index + 1),
                'quantity' => '1.0000',
                'unit_price' => $line['subtotal'],
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
