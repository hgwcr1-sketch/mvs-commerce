<?php

namespace Tests\Feature;

use App\Models\{Branch, CashDenomination, CashRegister, CashSession, Company, PaymentMethod, Permission, Product, ProductCategory, Role, Sale, SalePayment, Unit, User};
use App\Services\Cash\{CashExpectedAmountService, CashPaymentExpectedAmountService};
use App\Services\{CashDenominationProvisioner, CompanyCashSettingsProvisioner};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PosUsdCashTest extends TestCase
{
    use RefreshDatabase;

    public static function cashCases(): array
    {
        return [
            'A CRC change' => ['8000', '20', '0', 'crc_only', null, '-2100.0000', '20.0000', '2100.0000', '0.0000'],
            'B mixed cash' => ['8000', '10', '2950', 'crc_only', null, '2950.0000', '10.0000', '0.0000', '0.0000'],
            'USD change' => ['8000', '20', '0', 'usd_only', null, '0.0000', '15.8416', '0.0000', '4.1584'],
            'either CRC' => ['8000', '20', '0', 'either', 'CRC', '-2100.0000', '20.0000', '2100.0000', '0.0000'],
            'either USD' => ['8000', '20', '0', 'either', 'USD', '0.0000', '15.8416', '0.0000', '4.1584'],
        ];
    }

    #[DataProvider('cashCases')]
    public function test_cash_physical_amounts_and_idempotency(string $total, string $usd, string $crc, string $policy, ?string $currency, string $effectCrc, string $effectUsd, string $changeCrc, string $changeUsd): void
    {
        [$company, $branch, $user, $session, $cash, $product] = $this->context($total, $policy);
        $payload = $this->payload($session, $product, [['payment_method_id' => $cash->id, 'amount' => $total, 'received_amount' => $crc, 'received_amount_usd' => $usd, 'change_currency' => $currency]]);
        $this->postJson(route('pos.checkout'), $payload)->assertOk();
        $payment = SalePayment::sole();
        $this->assertSame($effectCrc, $payment->cash_effect_amount);
        $this->assertSame($effectUsd, $payment->cash_effect_amount_usd);
        $this->assertSame($changeCrc, $payment->change_amount);
        $this->assertSame($changeUsd, $payment->change_amount_usd);
        $this->assertSame(bcadd($crc, '0', 4), $payment->received_amount);
        $this->assertSame(bcadd($usd, '0', 4), $payment->received_amount_usd);
        $this->assertSame('505.0000', $payment->exchange_rate_snapshot);
        $this->assertSame(bcadd($total, '0', 4), $payment->amount);
        // Current configuration may change; the open session remains authoritative.
        app(CompanyCashSettingsProvisioner::class)->provision($company)->update(['accepts_usd' => false]);
        $this->postJson(route('pos.checkout'), $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('sale_payments', 1);
        $payload['payments'][0]['received_amount_usd'] = '30';
        $this->postJson(route('pos.checkout'), $payload)->assertConflict();
    }

    public function test_case_c_usd_with_card_and_three_way_mix(): void
    {
        [, , , $session, $cash, $product] = $this->context('15000');
        $card = $this->method($session->company_id, 'card');
        foreach ([['10', '0', '5050', '9950'], ['10', '950', '6000', '9000']] as [$usd, $crc, $cashAmount, $cardAmount]) {
            $this->postJson(route('pos.checkout'), $this->payload($session, $product, [
                ['payment_method_id' => $cash->id, 'amount' => $cashAmount, 'received_amount' => $crc, 'received_amount_usd' => $usd],
                ['payment_method_id' => $card->id, 'amount' => $cardAmount],
            ]))->assertOk();
            $sale = Sale::latest('id')->firstOrFail();
            $this->assertSame(bcadd($crc, '0', 4), $sale->payments()->where('payment_method_id', $cash->id)->firstOrFail()->cash_effect_amount);
            $this->assertSame('10.0000', $sale->payments()->where('payment_method_id', $cash->id)->firstOrFail()->cash_effect_amount_usd);
            $this->assertNull($sale->payments()->where('payment_method_id', $card->id)->firstOrFail()->received_amount_usd);
        }
    }

    public function test_crc_cash_card_and_sinpe_remain_unchanged(): void
    {
        [, , , $session, $cash, $product] = $this->context('8000');
        foreach ([$cash, $this->method($session->company_id, 'card'), $this->method($session->company_id, 'sinpe')] as $method) {
            $this->postJson(route('pos.checkout'), $this->payload($session, $product, [['payment_method_id' => $method->id, 'amount' => '8000', 'received_amount' => '10000']]))->assertOk();
            $payment = SalePayment::latest('id')->firstOrFail();
            $this->assertNull($payment->exchange_rate_snapshot);
            $this->assertSame($method->type === 'cash' ? '8000.0000' : '0.0000', $payment->cash_effect_amount);
        }
    }

    public function test_disabled_invalid_rate_policy_tampering_and_invalid_amounts_reject_without_writes(): void
    {
        [, , , $session, $cash, $product] = $this->context('8000');
        $base = ['payment_method_id' => $cash->id, 'amount' => '8000', 'received_amount' => '0', 'received_amount_usd' => '20'];
        foreach (['-1', 'NaN', 'Infinity', '1e3', '20.00001', '1'] as $invalid) {
            $this->postJson(route('pos.checkout'), $this->payload($session, $product, [array_replace($base, ['received_amount_usd' => $invalid])]))->assertUnprocessable();
        }
        foreach ([['change_currency' => 'USD'], ['usd_exchange_rate' => '999'], ['exchange_rate_snapshot' => '999'], ['cash_effect_amount_usd' => '50'], ['change_amount_usd' => '5'], ['amount' => '8001']] as $tampering) {
            $this->postJson(route('pos.checkout'), $this->payload($session, $product, [array_replace($base, $tampering)]))->assertUnprocessable();
        }
        foreach ([['accepts_usd_snapshot' => false], ['accepts_usd_snapshot' => true, 'usd_exchange_rate' => 0], ['usd_exchange_rate' => null], ['usd_exchange_rate' => 505, 'usd_change_policy_snapshot' => 'either']] as $settings) {
            $session->update($settings);
            $this->postJson(route('pos.checkout'), $this->payload($session, $product, [$base]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_usd_is_not_accepted_for_non_cash_or_another_branch_session(): void
    {
        [$company, , $user, $session, $cash, $product] = $this->context('8000');
        $card = $this->method($company->id, 'card');
        $payment = ['payment_method_id' => $card->id, 'amount' => '8000', 'received_amount_usd' => '20'];
        $this->postJson(route('pos.checkout'), $this->payload($session, $product, [$payment]))->assertUnprocessable();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Otra', 'code' => 'B', 'is_active' => true]);
        $user->branches()->attach($branch);
        $this->withSession(['active_branch_id' => $branch->id]);
        $payment['payment_method_id'] = $cash->id;
        $this->postJson(route('pos.checkout'), $this->payload($session, $product, [$payment]))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_usd_expected_and_closing_are_separate_from_crc_and_exclude_voids(): void
    {
        [$company, , , $session, $cash, $product] = $this->context('8000');
        $session->update(['opening_amount' => 10000, 'opening_amount_usd' => 5]);
        $payment = ['payment_method_id' => $cash->id, 'amount' => '8000', 'received_amount' => '0', 'received_amount_usd' => '20'];
        $this->postJson(route('pos.checkout'), $this->payload($session, $product, [$payment]))->assertOk();
        $this->postJson(route('pos.checkout'), $this->payload($session, $product, [$payment]))->assertOk();
        Sale::latest('id')->firstOrFail()->update(['status' => Sale::STATUS_VOIDED]);
        $this->postJson(route('pos.checkout'), $this->payload($session, $product, [$payment]))->assertOk();
        SalePayment::latest('id')->firstOrFail()->update(['status' => SalePayment::STATUS_VOIDED]);
        $expected = app(CashExpectedAmountService::class);
        $this->assertSame('25.0000', $expected->calculateUsdDecimal($session));
        $this->assertSame('7900.0000', $expected->calculateDecimal($session));
        app(CashDenominationProvisioner::class)->provision($company);
        $this->post(route('cash.closing.start', $session), ['request_token' => (string) Str::uuid()])->assertRedirect();
        $this->get(route('cash.closing.create', $session))->assertOk()->assertSee('Efectivo en dólares')->assertDontSee('Esperado US$');
        $counts = CashDenomination::forCompany($company->id)->forCurrency('CRC')->active()->get()->mapWithKeys(fn ($d) => [$d->id => 0])->all();
        $reports = app(CashPaymentExpectedAmountService::class)->methods($session)->mapWithKeys(fn ($m) => [$m->id => ['reported_amount' => $m->id === $cash->id ? '-2100' : '0']])->all();
        $payload = ['request_token' => (string) Str::uuid(), 'denominations' => $counts, 'payments' => $reports, 'counted_cash_usd' => '24'];
        $this->post(route('cash.closing.submit', $session), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('25.0000', $session->fresh()->expected_cash_usd);
        $this->assertSame('24.0000', $session->fresh()->counted_cash_usd);
        $this->assertSame('-1.0000', $session->fresh()->difference_amount_usd);
        $this->get(route('cash.closing.show', $session))->assertOk()->assertSee('Esperado US$')->assertSee('25.0000');
        $this->post(route('cash.closing.submit', $session), $payload)->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_pos_usd_preview_and_loyalty_use_existing_snapshot_and_summary(): void
    {
        [, , , $session] = $this->context('8000');
        $response = $this->get(route('pos.index'))->assertOk()->assertDontSee("unsupportedPaymentMethod(method) ? 'Próximamente'", false)->assertSee('Puntos de fidelización');
        $process = new Process(['node', base_path('tests/js/pos-usd.cjs')]);
        $process->setInput($response->getContent())->mustRun();
        $this->assertStringContainsString('USD UI OK', $process->getOutput());
    }

    private function context(string $total, string $policy = 'crc_only'): array
    {
        $company = Company::create(['trade_name' => 'USD Test', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P', 'is_active' => true]);
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cajero', 'is_active' => true]);
        foreach (['pos.acceder', 'ventas.crear', 'caja.cerrar', 'caja.ver', 'caja.administrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Caja', 'is_active' => true]));
        }
        $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);
        app(CompanyCashSettingsProvisioner::class)->provision($company)->update(['accepts_usd' => false, 'closure_email_recipients' => []]);
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'C', 'name' => 'Caja', 'is_active' => true]);
        $session = CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'opened_by' => $user->id, 'session_number' => 'S', 'status' => 'open', 'open_guard' => 'OPEN', 'opening_amount' => 0, 'opening_amount_usd' => 0, 'opened_at' => now(), 'accepts_usd_snapshot' => true, 'usd_exchange_rate' => '505', 'usd_change_policy_snapshot' => $policy, 'blind_closing_snapshot' => true, 'tolerance_snapshot' => 0]);
        $cash = $this->method($company->id, 'cash');
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General', 'slug' => 'general', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'unidad', 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto', 'internal_code' => 'P', 'cost' => 500, 'sale_price' => $total, 'tax_rate' => 0, 'track_inventory' => false, 'is_active' => true]);
        $product->fiscal_profile_id = \App\Models\FiscalProfile::query()->where('tax_code', '01')->where('tax_rate_code', '10')->value('id');
        $product->save();
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        return [$company, $branch, $user, $session, $cash, $product];
    }

    private function method(int $companyId, string $type): PaymentMethod
    {
        return PaymentMethod::create(['company_id' => $companyId, 'code' => $type, 'name' => $type, 'type' => $type, 'is_active' => true, 'affects_cash' => $type === 'cash', 'allows_change' => $type === 'cash', 'requires_reference' => false]);
    }

    private function payload(CashSession $session, Product $product, array $payments): array
    {
        return ['checkout_token' => (string) Str::uuid(), 'cash_session_id' => $session->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => $payments];
    }
}
