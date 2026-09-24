<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Unit;
use App\Models\User;
use App\Services\Cash\CashPaymentBreakdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashPaymentBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function context(array $permissions = ['caja.ver', 'caja.administrar', 'ventas.ver']): array
    {
        $suffix = Str::lower(Str::random(6));
        $company = Company::create(['trade_name' => 'Caja Break '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'CB'.$suffix, 'is_active' => true]);
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CR'.$suffix, 'name' => 'Caja 1', 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Caja '.$suffix, 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Caja', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $register, $user];
    }

    private function openCashSession(Company $company, Branch $branch, CashRegister $register, User $user): CashSession
    {
        return CashSession::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'session_number' => 'S-'.Str::upper(Str::random(6)),
            'status' => CashSession::STATUS_OPEN,
            'opened_by' => $user->id,
            'opened_at' => now(),
            'opening_amount' => '0',
            'open_guard' => CashSession::OPEN_GUARD,
        ]);
    }

    private function method(Company $company, string $type, string $code): PaymentMethod
    {
        return PaymentMethod::create([
            'company_id' => $company->id,
            'name' => ucfirst($code).' '.Str::random(3),
            'code' => $code.'-'.Str::random(4),
            'type' => $type,
            'affects_cash' => $type === PaymentMethod::TYPE_CASH,
            'is_active' => true,
        ]);
    }

    private function sale(Company $company, Branch $branch, User $user, CashSession $session, string $total, string $status = Sale::STATUS_COMPLETED): Sale
    {
        return Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cash_session_id' => $session->id,
            'user_id' => $user->id,
            'customer_id' => null,
            'sale_number' => 'V-'.Str::upper(Str::random(6)),
            'subtotal' => $total,
            'tax_total' => '0',
            'total' => $total,
            'status' => $status,
        ]);
    }

    private function pay(Sale $sale, CashSession $session, PaymentMethod $method, string $amount, ?string $usd = null, string $status = SalePayment::STATUS_COMPLETED, ?User $user = null, ?string $reference = null): SalePayment
    {
        return SalePayment::create([
            'sale_id' => $sale->id,
            'cash_session_id' => $session->id,
            'payment_method_id' => $method->id,
            'affects_cash_snapshot' => $method->affects_cash,
            'created_by' => ($user ?? User::firstOrFail())->id,
            'amount' => $amount,
            'cash_effect_amount' => $amount,
            'cash_effect_amount_usd' => $usd,
            'reference' => $reference,
            'status' => $status,
        ]);
    }

    public function test_totals_group_by_method_and_mixed_payment_counts_only_its_part(): void
    {
        [$company, $branch, $register, $user] = $this->context();
        $session = $this->openCashSession($company, $branch, $register, $user);
        $cash = $this->method($company, PaymentMethod::TYPE_CASH, 'cash');
        $card = $this->method($company, PaymentMethod::TYPE_CARD, 'card');
        $sinpe = $this->method($company, PaymentMethod::TYPE_SINPE, 'sinpe');

        $mixed = $this->sale($company, $branch, $user, $session, '10000.00');
        $this->pay($mixed, $session, $cash, '4000.00', null, SalePayment::STATUS_COMPLETED, $user, 'EF-1');
        $this->pay($mixed, $session, $card, '6000.00', null, SalePayment::STATUS_COMPLETED, $user, 'TARJ-1');

        $onlyCash = $this->sale($company, $branch, $user, $session, '2500.00');
        $this->pay($onlyCash, $session, $cash, '2500.00');

        $onlySinpe = $this->sale($company, $branch, $user, $session, '1500.00');
        $this->pay($onlySinpe, $session, $sinpe, '1500.00', null, SalePayment::STATUS_COMPLETED, $user, 'SINPE-1');

        $summary = app(CashPaymentBreakdownService::class)->summarize($session);

        $byMethod = collect($summary['monetary'])->keyBy('code');
        $this->assertSame('6500.0000', $byMethod[$cash->code]['total_crc']);
        $this->assertSame('6000.0000', $byMethod[$card->code]['total_crc']);
        $this->assertSame('1500.0000', $byMethod[$sinpe->code]['total_crc']);
        $this->assertSame('14000.0000', $summary['collected_crc']);
        $this->assertSame(2, $byMethod[$cash->code]['payments_count']);
        $this->assertSame(0, $summary['non_monetary']->count());
    }

    public function test_voided_sales_and_payments_are_excluded(): void
    {
        [$company, $branch, $register, $user] = $this->context();
        $session = $this->openCashSession($company, $branch, $register, $user);
        $cash = $this->method($company, PaymentMethod::TYPE_CASH, 'cash');

        $ok = $this->sale($company, $branch, $user, $session, '1000.00');
        $this->pay($ok, $session, $cash, '1000.00');

        $voided = $this->sale($company, $branch, $user, $session, '999.00', Sale::STATUS_VOIDED);
        $this->pay($voided, $session, $cash, '999.00');

        $voidedPayment = $this->sale($company, $branch, $user, $session, '888.00');
        $this->pay($voidedPayment, $session, $cash, '888.00', null, SalePayment::STATUS_VOIDED);

        $summary = app(CashPaymentBreakdownService::class)->summarize($session);
        $this->assertSame('1000.0000', $summary['monetary'][0]['total_crc']);
        $this->assertSame(1, $summary['monetary'][0]['payments_count']);
    }

    public function test_credit_and_loyalty_are_non_monetary_and_not_summed_as_collected(): void
    {
        [$company, $branch, $register, $user] = $this->context();
        $session = $this->openCashSession($company, $branch, $register, $user);
        $cash = $this->method($company, PaymentMethod::TYPE_CASH, 'cash');
        $credit = $this->method($company, PaymentMethod::TYPE_CREDIT, 'credit');
        $loyalty = $this->method($company, PaymentMethod::TYPE_LOYALTY_POINTS, 'pts');

        $sale = $this->sale($company, $branch, $user, $session, '5000.00');
        $this->pay($sale, $session, $cash, '2000.00');
        $this->pay($sale, $session, $credit, '2000.00');
        $this->pay($sale, $session, $loyalty, '1000.00');

        $summary = app(CashPaymentBreakdownService::class)->summarize($session);
        $this->assertSame('2000.0000', $summary['collected_crc']);
        $this->assertCount(1, $summary['monetary']);
        $this->assertCount(2, $summary['non_monetary']);
        $types = collect($summary['non_monetary'])->pluck('type')->all();
        $this->assertContains(PaymentMethod::TYPE_CREDIT, $types);
        $this->assertContains(PaymentMethod::TYPE_LOYALTY_POINTS, $types);
    }

    public function test_crc_and_usd_are_kept_separate(): void
    {
        [$company, $branch, $register, $user] = $this->context();
        $session = $this->openCashSession($company, $branch, $register, $user);
        $cash = $this->method($company, PaymentMethod::TYPE_CASH, 'cash');

        $sale = $this->sale($company, $branch, $user, $session, '10000.00');
        $this->pay($sale, $session, $cash, '5500.00', '10.0000');

        $summary = app(CashPaymentBreakdownService::class)->summarize($session);
        $this->assertSame('5500.0000', $summary['monetary'][0]['total_crc']);
        $this->assertSame('10.0000', $summary['monetary'][0]['total_usd']);
        $this->assertSame('5500.0000', $summary['collected_crc']);
        $this->assertSame('10.0000', $summary['collected_usd']);
    }

    public function test_details_expose_only_the_method_part_with_receipt_actions(): void
    {
        [$company, $branch, $register, $user] = $this->context();
        $session = $this->openCashSession($company, $branch, $register, $user);
        $cash = $this->method($company, PaymentMethod::TYPE_CASH, 'cash');
        $card = $this->method($company, PaymentMethod::TYPE_CARD, 'card');
        $credit = $this->method($company, PaymentMethod::TYPE_CREDIT, 'credit');
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente Mixto', 'is_active' => true]);

        $sale = $this->sale($company, $branch, $user, $session, '9000.00');
        $sale->update(['customer_id' => $customer->id]);
        $this->pay($sale, $session, $cash, '3000.00', null, SalePayment::STATUS_COMPLETED, $user, 'REF-CASH');
        $this->pay($sale, $session, $card, '6000.00', null, SalePayment::STATUS_COMPLETED, $user, 'REF-CARD');

        $creditSale = $this->sale($company, $branch, $user, $session, '1000.00');
        $this->pay($creditSale, $session, $credit, '1000.00', null, SalePayment::STATUS_COMPLETED, $user, 'REF-CRED');

        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('cash.history.show', $session));

        $response->assertOk()
            ->assertSee('Totales por medio de pago')
            ->assertSee('Cliente Mixto')
            ->assertSee('REF-CASH')
            ->assertSee('REF-CARD')
            ->assertSee('REF-CRED')
            ->assertSee(route('ventas.show', $sale->id), false)
            ->assertSee('No monetario');
    }

    public function test_session_scope_and_permissions_are_enforced(): void
    {
        [$company, $branch, $register, $user] = $this->context();
        $session = $this->openCashSession($company, $branch, $register, $user);
        $cash = $this->method($company, PaymentMethod::TYPE_CASH, 'cash');
        $sale = $this->sale($company, $branch, $user, $session, '1000.00');
        $this->pay($sale, $session, $cash, '1000.00', null, SalePayment::STATUS_COMPLETED, $user);

        [$otherCompany, $otherBranch, $otherRegister, $otherUser] = $this->context();
        $otherSession = $this->openCashSession($otherCompany, $otherBranch, $otherRegister, $otherUser);

        $this->actingAs($otherUser)->withSession(['active_company_id' => $otherCompany->id, 'active_branch_id' => $otherBranch->id])
            ->get(route('cash.history.show', $session))->assertNotFound();

        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('cash.history.show', $otherSession))->assertNotFound();

        $viewerRole = Role::create(['company_id' => $company->id, 'name' => 'Solo ver', 'is_active' => true]);
        $ver = Permission::firstOrCreate(['name' => 'caja.ver'], ['label' => 'caja.ver', 'module' => 'Caja', 'is_active' => true]);
        $viewerRole->permissions()->syncWithoutDetaching($ver);
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->companies()->attach($company->id, ['role_id' => $viewerRole->id]);
        $viewer->branches()->attach($branch->id);

        $this->actingAs($viewer)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('cash.history.show', $session))
            ->assertOk()
            ->assertDontSee('Totales por medio de pago');

        $noPermRole = Role::create(['company_id' => $company->id, 'name' => 'Sin permisos', 'is_active' => true]);
        $noPerm = User::factory()->create(['is_active' => true]);
        $noPerm->companies()->attach($company->id, ['role_id' => $noPermRole->id]);
        $noPerm->branches()->attach($branch->id);

        $this->actingAs($noPerm)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('cash.history.show', $session))->assertForbidden();
    }

    public function test_credit_notes_do_not_appear_without_sale_payments(): void
    {
        [$company, $branch, $register, $user] = $this->context();
        $session = $this->openCashSession($company, $branch, $register, $user);
        $cash = $this->method($company, PaymentMethod::TYPE_CASH, 'cash');
        $sale = $this->sale($company, $branch, $user, $session, '7000.00');
        $this->pay($sale, $session, $cash, '7000.00');

        $summary = app(CashPaymentBreakdownService::class)->summarize($session);
        $this->assertCount(1, $summary['monetary']);
        $this->assertSame('7000.0000', $summary['collected_crc']);
        $this->assertCount(1, $summary['details']);
    }
}
