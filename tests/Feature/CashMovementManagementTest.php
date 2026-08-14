<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashSessionEvent;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Cash\CashExpectedAmountService;
use App\Services\CompanyCashSettingsProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashMovementManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_expected_cash_uses_fund_valid_cash_sales_and_movement_directions(): void
    {
        [$company, $branch, $user, $register, $session] = $this->context();
        $method = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash', 'name' => 'Efectivo', 'type' => PaymentMethod::TYPE_CASH, 'is_active' => true, 'affects_cash' => true]);
        $sale = $this->sale($company, $branch, $user, $session, Sale::STATUS_COMPLETED, 'POS-1');
        SalePayment::create(['sale_id' => $sale->id, 'cash_session_id' => $session->id, 'payment_method_id' => $method->id, 'affects_cash_snapshot' => true, 'created_by' => $user->id, 'amount' => 500, 'received_amount' => 2000, 'change_amount' => 1500, 'cash_effect_amount' => 500, 'status' => SalePayment::STATUS_COMPLETED]);
        $voidedSale = $this->sale($company, $branch, $user, $session, Sale::STATUS_VOIDED, 'POS-2');
        SalePayment::create(['sale_id' => $voidedSale->id, 'cash_session_id' => $session->id, 'payment_method_id' => $method->id, 'affects_cash_snapshot' => true, 'created_by' => $user->id, 'amount' => 999, 'received_amount' => 999, 'change_amount' => 0, 'cash_effect_amount' => 999, 'status' => SalePayment::STATUS_COMPLETED]);
        $this->rawMovement($session, $user, CashMovement::TYPE_ENTRY, CashMovement::DIRECTION_IN, 200);
        $this->rawMovement($session, $user, CashMovement::TYPE_EXIT, CashMovement::DIRECTION_OUT, 100);
        $this->rawMovement($session, $user, CashMovement::TYPE_WITHDRAWAL, CashMovement::DIRECTION_OUT, 50);

        $this->assertSame(1550.0, app(CashExpectedAmountService::class)->calculate($session));
    }

    public function test_entry_exit_and_withdrawal_derive_direction_and_create_auditable_events(): void
    {
        [$company, $branch, $user, , $session] = $this->context();

        foreach ([CashMovement::TYPE_ENTRY => CashMovement::DIRECTION_IN, CashMovement::TYPE_EXIT => CashMovement::DIRECTION_OUT, CashMovement::TYPE_WITHDRAWAL => CashMovement::DIRECTION_OUT] as $type => $direction) {
            $this->postMovement($user, $company, $branch, $session, $type, 100, ['direction' => $direction === 'in' ? 'out' : 'in'])
                ->assertSessionHasNoErrors();
            $movement = CashMovement::where('type', $type)->firstOrFail();
            $this->assertSame($direction, $movement->direction);
            $this->assertSame('Motivo '.$type, $movement->reason);
            $this->assertSame('Nota '.$type, $movement->notes);
            $this->assertDatabaseHas('cash_session_events', ['cash_session_id' => $session->id, 'event_type' => $type, 'user_id' => $user->id]);
        }
    }

    public function test_permissions_individual_isolation_and_shared_operation_are_enforced(): void
    {
        [$company, $branch, $owner, , $session] = $this->context();
        $unauthorized = $this->user($company, $branch, []);
        $operator = $this->user($company, $branch, ['caja.movimientos', 'caja.ver']);

        $this->postMovement($unauthorized, $company, $branch, $session, CashMovement::TYPE_ENTRY, 100)->assertForbidden();
        $this->postMovement($operator, $company, $branch, $session, CashMovement::TYPE_ENTRY, 100)->assertSessionHasErrors('cash_session_id');
        $this->getAs($operator, $company, $branch, route('cash.movements.create', $session))->assertForbidden();
        $this->getAs($operator, $company, $branch, route('cash.movements.index', $session))->assertForbidden();

        CompanyCashSetting::where('company_id', $company->id)->update(['session_mode' => CompanyCashSetting::SESSION_MODE_SHARED]);
        $this->postMovement($operator, $company, $branch, $session, CashMovement::TYPE_ENTRY, 100)->assertSessionHasNoErrors();
        $viewer = $this->user($company, $branch, ['caja.ver', 'caja.ver_todas']);
        $this->getAs($viewer, $company, $branch, route('cash.movements.index', $session))->assertOk();
        $this->assertSame(1, CashMovement::count());
        $this->assertSame($owner->id, $session->opened_by);
    }

    public function test_foreign_company_branch_closed_session_and_inactive_register_are_rejected(): void
    {
        [$company, $branch, $user, $register, $session] = $this->context();
        [$otherCompany, $otherBranch, $otherUser, , $otherSession] = $this->context('Otra');

        $this->postMovement($user, $company, $branch, $otherSession, CashMovement::TYPE_ENTRY, 100)->assertSessionHasErrors('cash_session_id');
        $session->update(['status' => CashSession::STATUS_CLOSED, 'open_guard' => null]);
        $this->postMovement($user, $company, $branch, $session, CashMovement::TYPE_ENTRY, 100)->assertSessionHasErrors('cash_session_id');
        $session->update(['status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD]);
        $register->update(['is_active' => false]);
        $this->postMovement($user, $company, $branch, $session, CashMovement::TYPE_ENTRY, 100)->assertSessionHasErrors('cash_session_id');
        $this->assertSame(0, CashMovement::count());
        $this->assertNotSame($otherCompany->id, $company->id);
        $this->assertNotSame($otherBranch->id, $branch->id);
        $this->assertNotSame($otherUser->id, $user->id);
    }

    public function test_validation_requires_positive_integer_amount_reason_and_valid_type(): void
    {
        [$company, $branch, $user, , $session] = $this->context();

        foreach ([0, -1, '1.5'] as $amount) {
            $this->postMovement($user, $company, $branch, $session, CashMovement::TYPE_ENTRY, $amount)->assertSessionHasErrors('amount');
        }
        $this->postMovement($user, $company, $branch, $session, 'invalid', 1)->assertSessionHasErrors('type');
        $this->postMovement($user, $company, $branch, $session, CashMovement::TYPE_ENTRY, 1, ['reason' => ' '])->assertSessionHasErrors('reason');
        $this->assertSame(0, CashMovement::count());
    }

    public function test_output_above_available_cash_is_rejected_and_sequential_requests_cannot_overdraw(): void
    {
        [$company, $branch, $user, , $session] = $this->context();
        $session->update(['opening_amount' => 100]);

        $this->postMovement($user, $company, $branch, $session, CashMovement::TYPE_EXIT, 101)
            ->assertSessionHasErrors('amount');
        $this->postMovement($user, $company, $branch, $session, CashMovement::TYPE_WITHDRAWAL, 80)
            ->assertSessionHasNoErrors();
        $this->postMovement($user, $company, $branch, $session, CashMovement::TYPE_EXIT, 30)
            ->assertSessionHasErrors('amount');

        $this->assertSame(1, CashMovement::count());
        $this->assertSame(1, CashSessionEvent::where('event_type', CashSessionEvent::TYPE_WITHDRAWAL)->count());
        $this->assertSame(20.0, app(CashExpectedAmountService::class)->calculate($session->fresh()));
    }

    public function test_request_token_is_idempotent_per_session(): void
    {
        [$company, $branch, $user, , $session] = $this->context();
        $token = (string) Str::uuid();

        $this->postMovement($user, $company, $branch, $session, CashMovement::TYPE_ENTRY, 100, ['request_token' => $token])->assertSessionHasNoErrors();
        $this->postMovement($user, $company, $branch, $session, CashMovement::TYPE_ENTRY, 100, ['request_token' => $token])->assertSessionHasNoErrors();

        $this->assertSame(1, CashMovement::count());
        $this->assertSame(1, CashSessionEvent::where('event_type', CashSessionEvent::TYPE_ENTRY)->count());
    }

    public function test_event_failure_rolls_back_movement(): void
    {
        [$company, $branch, $user, , $session] = $this->context();
        CashSessionEvent::creating(fn () => throw new \RuntimeException('event failure'));
        $this->withoutExceptionHandling();

        try {
            $this->postMovement($user, $company, $branch, $session, CashMovement::TYPE_ENTRY, 100);
            $this->fail('La excepción esperada no fue lanzada.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('event failure', $exception->getMessage());
        }

        $this->assertSame(0, CashMovement::count());
        $this->assertSame(0, CashSessionEvent::count());
    }

    public function test_history_is_ordered_uses_company_timezone_and_renders_required_actions(): void
    {
        [$company, $branch, $user, , $session] = $this->context();
        $company->update(['timezone' => 'America/Costa_Rica']);
        $old = $this->rawMovement($session, $user, CashMovement::TYPE_ENTRY, CashMovement::DIRECTION_IN, 100, 'Antiguo', CarbonImmutable::parse('2026-08-14 14:00:00', 'UTC'));
        $new = $this->rawMovement($session, $user, CashMovement::TYPE_EXIT, CashMovement::DIRECTION_OUT, 50, 'Nuevo', CarbonImmutable::parse('2026-08-14 15:30:00', 'UTC'));
        $stored = $new->getRawOriginal('occurred_at');

        $response = $this->getAs($user, $company, $branch, route('cash.movements.index', $session));
        $response->assertOk()
            ->assertSeeInOrder(['Nuevo', 'Antiguo'])
            ->assertSee('14/08/2026 09:30')
            ->assertSee('Efectivo esperado actual')
            ->assertSee('Volver')
            ->assertSee('bg-amber-500 px-4 py-3 font-normal text-black hover:bg-amber-600', false);
        $this->assertSame($stored, $new->fresh()->getRawOriginal('occurred_at'));
        $this->assertSame('2026-08-14 15:30:00', $new->fresh()->occurred_at->utc()->format('Y-m-d H:i:s'));
        $this->assertLessThan($new->occurred_at, $old->occurred_at);
    }

    public function test_invalid_company_timezone_falls_back_to_utc(): void
    {
        [$company, $branch, $user, , $session] = $this->context();
        $company->update(['timezone' => 'Invalid/Timezone']);
        $this->rawMovement($session, $user, CashMovement::TYPE_ENTRY, CashMovement::DIRECTION_IN, 100, 'UTC', CarbonImmutable::parse('2026-08-14 15:30:00', 'UTC'));

        $this->getAs($user, $company, $branch, route('cash.movements.index', $session))
            ->assertOk()
            ->assertSee('14/08/2026 15:30');
    }

    public function test_no_edit_update_or_delete_routes_exist(): void
    {
        $this->assertFalse(Route::has('cash.movements.edit'));
        $this->assertFalse(Route::has('cash.movements.update'));
        $this->assertFalse(Route::has('cash.movements.destroy'));
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P-'.uniqid(), 'is_active' => true]);
        app(CompanyCashSettingsProvisioner::class)->provision($company);
        $user = $this->user($company, $branch, ['caja.ver', 'caja.movimientos']);
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'cash'.uniqid(), 'name' => 'Caja 1', 'is_active' => true, 'is_default' => true]);
        $session = CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.$company->id, 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'currency_code' => 'CRC', 'opening_amount' => 1000, 'opened_at' => now()]);

        return [$company, $branch, $user, $register, $session];
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Caja', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function postMovement(User $user, Company $company, Branch $branch, CashSession $session, string $type, mixed $amount, array $overrides = [])
    {
        return $this->actingAs($user)->withSession($this->sessionContext($company, $branch))->post(
            route('cash.movements.store', $session),
            array_merge(['type' => $type, 'amount' => $amount, 'reason' => 'Motivo '.$type, 'notes' => 'Nota '.$type, 'request_token' => (string) Str::uuid()], $overrides),
        );
    }

    private function getAs(User $user, Company $company, Branch $branch, string $url)
    {
        return $this->actingAs($user)->withSession($this->sessionContext($company, $branch))->get($url);
    }

    private function sessionContext(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function sale(Company $company, Branch $branch, User $user, CashSession $session, string $status, string $number): Sale
    {
        return Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'cash_session_id' => $session->id, 'sale_number' => $number, 'checkout_token' => Str::uuid(), 'request_fingerprint' => hash('sha256', $number), 'status' => $status, 'completed_at' => now()]);
    }

    private function rawMovement(CashSession $session, User $user, string $type, string $direction, float $amount, string $reason = 'Movimiento', ?CarbonImmutable $occurredAt = null): CashMovement
    {
        return CashMovement::create(['company_id' => $session->company_id, 'branch_id' => $session->branch_id, 'cash_register_id' => $session->cash_register_id, 'cash_session_id' => $session->id, 'type' => $type, 'direction' => $direction, 'amount' => $amount, 'concept' => ucfirst($type), 'reason' => $reason, 'created_by' => $user->id, 'occurred_at' => $occurredAt ?? now()]);
    }
}
