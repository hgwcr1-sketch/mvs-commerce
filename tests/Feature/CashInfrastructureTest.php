<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashCountDetail;
use App\Models\CashDenomination;
use App\Models\CashPaymentReconciliation;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\CashDenominationProvisioner;
use App\Services\CompanyCashSettingsProvisioner;
use App\Services\CompanyProvisioner;
use Database\Seeders\CashInfrastructureSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_settings_defaults_disable_usd_and_enable_blind_closing(): void
    {
        $company = $this->company('Empresa predeterminada');
        $settings = app(CompanyCashSettingsProvisioner::class)->provision($company)->fresh();

        $this->assertFalse($settings->require_open_session);
        $this->assertFalse($settings->accepts_usd);
        $this->assertTrue($settings->blind_closing);
        $this->assertSame(CompanyCashSetting::USD_CHANGE_CRC_ONLY, $settings->usd_change_policy);
        $this->assertNull($settings->usd_exchange_rate_min);
        $this->assertNull($settings->closure_email_recipients);
    }

    public function test_cash_settings_are_independent_and_email_recipients_are_cast_to_array(): void
    {
        $first = app(CompanyCashSettingsProvisioner::class)->provision($this->company('Empresa USD'));
        $second = app(CompanyCashSettingsProvisioner::class)->provision($this->company('Empresa CRC'));
        $first->update(['accepts_usd' => true, 'usd_exchange_rate_min' => '500.2500', 'closure_email_recipients' => ['cierre@example.test', 'admin@example.test']]);

        $this->assertTrue($first->fresh()->accepts_usd);
        $this->assertSame('500.2500', $first->fresh()->usd_exchange_rate_min);
        $this->assertSame(['cierre@example.test', 'admin@example.test'], $first->fresh()->closure_email_recipients);
        $this->assertFalse($second->fresh()->accepts_usd);
    }

    public function test_settings_provisioner_is_idempotent_and_preserves_customizations(): void
    {
        $company = $this->company('Empresa personalizada');
        $provisioner = app(CompanyCashSettingsProvisioner::class);
        $settings = $provisioner->provision($company);
        $settings->update(['blind_closing' => false, 'accepts_usd' => true, 'usd_change_policy' => CompanyCashSetting::USD_CHANGE_EITHER]);
        $provisioner->provision($company);

        $this->assertSame(1, CompanyCashSetting::where('company_id', $company->id)->count());
        $this->assertFalse($settings->fresh()->blind_closing);
        $this->assertTrue($settings->fresh()->accepts_usd);
        $this->assertSame(CompanyCashSetting::USD_CHANGE_EITHER, $settings->fresh()->usd_change_policy);
    }

    public function test_cash_session_keeps_usd_and_blind_closing_snapshots_with_user(): void
    {
        [$company, $branch, $user, $register] = $this->context('Empresa sesión');
        $session = $this->cashSession($company, $branch, $user, $register, [
            'accepts_usd_snapshot' => true,
            'usd_exchange_rate' => '532.7500',
            'exchange_rate_entered_by' => $user->id,
            'opening_amount_usd' => '25.5000',
            'blind_closing_snapshot' => false,
            'usd_change_policy_snapshot' => CompanyCashSetting::USD_CHANGE_EITHER,
        ])->fresh();

        $this->assertTrue($session->accepts_usd_snapshot);
        $this->assertSame('532.7500', $session->usd_exchange_rate);
        $this->assertSame($user->id, $session->exchangeRateEnteredBy->id);
        $this->assertSame('25.5000', $session->opening_amount_usd);
        $this->assertFalse($session->blind_closing_snapshot);
    }

    public function test_session_without_usd_accepts_null_rate_and_zero_usd_funds(): void
    {
        [$company, $branch, $user, $register] = $this->context('Empresa sin USD');
        $session = $this->cashSession($company, $branch, $user, $register)->fresh();

        $this->assertFalse($session->accepts_usd_snapshot);
        $this->assertNull($session->usd_exchange_rate);
        $this->assertNull($session->exchange_rate_entered_by);
        $this->assertSame('0.0000', $session->opening_amount_usd);
        $this->assertNull($session->expected_cash_usd);
    }

    public function test_crc_denominations_are_idempotent_and_preserve_customizations(): void
    {
        $company = $this->company('Empresa denominaciones');
        $provisioner = app(CashDenominationProvisioner::class);
        $provisioner->provision($company);
        $denomination = CashDenomination::forCompany($company->id)->forCurrency('CRC')->where('value', 50000)->firstOrFail();
        $denomination->update(['label' => 'Valor personalizado', 'is_active' => false, 'sort_order' => 999]);
        $provisioner->provision($company);

        $this->assertSame(12, CashDenomination::forCompany($company->id)->forCurrency('CRC')->count());
        $this->assertSame('Valor personalizado', $denomination->fresh()->label);
        $this->assertFalse($denomination->fresh()->is_active);
        $this->assertSame(999, $denomination->fresh()->sort_order);
    }

    public function test_denominations_are_isolated_by_company(): void
    {
        $first = $this->company('Empresa uno');
        $second = $this->company('Empresa dos');
        $this->seed(CashInfrastructureSeeder::class);

        $this->assertSame(12, CashDenomination::forCompany($first->id)->count());
        $this->assertSame(12, CashDenomination::forCompany($second->id)->count());
        $this->assertEmpty(CashDenomination::forCompany($first->id)->pluck('id')->intersect(CashDenomination::forCompany($second->id)->pluck('id')));
    }

    public function test_count_detail_keeps_quantity_and_historical_value_snapshot(): void
    {
        [$company, $branch, $user, $register] = $this->context('Empresa conteo');
        app(CashDenominationProvisioner::class)->provision($company);
        $session = $this->cashSession($company, $branch, $user, $register);
        $denomination = CashDenomination::forCompany($company->id)->where('value', 1000)->firstOrFail();
        $detail = CashCountDetail::create(['cash_session_id' => $session->id, 'cash_denomination_id' => $denomination->id, 'count_type' => CashCountDetail::TYPE_OPENING, 'quantity' => 3, 'denomination_value' => '1000.0000', 'total_amount' => '3000.0000', 'counted_by' => $user->id, 'counted_at' => now()])->fresh();
        $denomination->update(['value' => '1100.0000']);

        $this->assertSame(3, $detail->quantity);
        $this->assertSame('1000.0000', $detail->denomination_value);
        $this->assertSame('3000.0000', $detail->total_amount);
    }

    public function test_same_denomination_cannot_repeat_in_same_count_type(): void
    {
        [$company, $branch, $user, $register] = $this->context('Empresa restricción');
        app(CashDenominationProvisioner::class)->provision($company);
        $session = $this->cashSession($company, $branch, $user, $register);
        $denomination = CashDenomination::forCompany($company->id)->firstOrFail();
        $data = ['cash_session_id' => $session->id, 'cash_denomination_id' => $denomination->id, 'count_type' => CashCountDetail::TYPE_CLOSING, 'quantity' => 1, 'denomination_value' => $denomination->value, 'total_amount' => $denomination->value, 'counted_by' => $user->id, 'counted_at' => now()];
        CashCountDetail::create($data);

        $this->expectException(QueryException::class);
        CashCountDetail::create($data);
    }

    public function test_reconciliation_is_dynamic_and_unique_per_payment_method(): void
    {
        [$company, $branch, $user, $register] = $this->context('Empresa conciliación');
        $session = $this->cashSession($company, $branch, $user, $register);
        $paypal = PaymentMethod::create(['company_id' => $company->id, 'code' => 'paypal', 'name' => 'PayPal', 'type' => PaymentMethod::TYPE_OTHER, 'is_active' => true, 'affects_cash' => false, 'requires_reference' => true, 'allows_change' => false]);
        $reconciliation = CashPaymentReconciliation::create(['cash_session_id' => $session->id, 'payment_method_id' => $paypal->id, 'expected_amount' => '100.2500', 'reported_amount' => '100.0000', 'difference_amount' => '-0.2500', 'reference' => 'PAYPAL-1', 'reconciled_by' => $user->id, 'reconciled_at' => now()])->fresh();

        $this->assertSame('PayPal', $reconciliation->paymentMethod->name);
        $this->assertSame('-0.2500', $reconciliation->difference_amount);
        $this->expectException(QueryException::class);
        CashPaymentReconciliation::create($reconciliation->only(['cash_session_id', 'payment_method_id', 'expected_amount', 'reported_amount', 'difference_amount', 'reference', 'reconciled_by', 'reconciled_at']));
    }

    public function test_sales_and_payments_keep_nullable_cash_fields_for_history(): void
    {
        [$company, $branch, $user] = $this->context('Empresa historial');
        $method = PaymentMethod::create(['company_id' => $company->id, 'code' => 'legacy', 'name' => 'Histórico', 'type' => PaymentMethod::TYPE_OTHER, 'is_active' => true]);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'sale_number' => 'POS-HIST', 'checkout_token' => Str::uuid(), 'request_fingerprint' => hash('sha256', 'history'), 'status' => Sale::STATUS_COMPLETED, 'completed_at' => now()]);
        $payment = SalePayment::create(['sale_id' => $sale->id, 'payment_method_id' => $method->id, 'created_by' => $user->id, 'amount' => 1, 'received_amount' => 1, 'change_amount' => 0]);

        $this->assertNull($sale->fresh()->cash_session_id);
        $this->assertNull($payment->fresh()->cash_session_id);
        $this->assertNull($payment->fresh()->affects_cash_snapshot);
        $this->assertNull($payment->fresh()->cash_effect_amount);
    }

    public function test_new_company_receives_settings_and_denominations_but_no_register(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = app(CompanyProvisioner::class)->provision(User::factory()->create(), ['trade_name' => 'Empresa futura']);

        $this->assertDatabaseHas('company_cash_settings', ['company_id' => $company->id, 'accepts_usd' => false, 'blind_closing' => true]);
        $this->assertSame(12, CashDenomination::forCompany($company->id)->count());
        $this->assertSame(0, CashRegister::forCompany($company->id)->count());
    }

    public function test_seven_cash_permissions_are_provisioned_only_to_administrator(): void
    {
        $company = $this->company('Empresa permisos');
        $administrator = Role::create(['company_id' => $company->id, 'name' => 'Administrador', 'is_active' => true]);
        $other = Role::create(['company_id' => $company->id, 'name' => 'Cajero', 'is_active' => true]);
        $this->seed(PermissionSeeder::class);
        $permissions = ['caja.abrir', 'caja.ver', 'caja.movimientos', 'caja.cerrar', 'caja.ver_todas', 'caja.autorizar_diferencia', 'caja.administrar'];

        $this->assertSame(7, $administrator->permissions()->whereIn('name', $permissions)->count());
        $this->assertSame(0, $other->permissions()->whereIn('name', $permissions)->count());
    }

    public function test_provisioning_does_not_send_email(): void
    {
        Mail::fake();
        $company = $this->company('Empresa sin correo');
        app(CompanyCashSettingsProvisioner::class)->provision($company);
        app(CashDenominationProvisioner::class)->provision($company);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    private function company(string $name): Company
    {
        return Company::create(['trade_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }

    private function context(string $name): array
    {
        $company = $this->company($name);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P-'.$company->id, 'is_active' => true]);
        $user = User::factory()->create();
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CAJA-1', 'name' => 'Caja 1']);
        return [$company, $branch, $user, $register];
    }

    private function cashSession(Company $company, Branch $branch, User $user, CashRegister $register, array $overrides = []): CashSession
    {
        return CashSession::create([...['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-00000001', 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'currency_code' => 'CRC', 'opening_amount' => 0, 'opened_at' => now()], ...$overrides]);
    }
}
