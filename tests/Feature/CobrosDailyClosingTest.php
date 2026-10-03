<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosDailyClosing;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cierre del día CobrOS: el cobrador declara totales por medio de pago,
 * adjunta evidencias y envía el cierre al dueño; el dueño lo revisa en el
 * drill-down con diferencias declarado vs sistema. Todo bajo /cobros/*.
 */
class CobrosDailyClosingTest extends TestCase
{
    use RefreshDatabase;

    public function test_collector_sees_real_day_totals_and_the_navigation_entry(): void
    {
        [$c, $b] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '5000.0000');
        $this->pay($c, $account, $collector, '1000.00');

        $response = $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.closing.show'))
            ->assertOk()
            ->assertSee('Cierre del día')
            ->assertSee(route('cobros.closing.show'));

        $totals = $response->viewData('totals');
        $this->assertSame('1000.0000', $totals['buckets']['cash']);
        $this->assertSame('0.0000', $totals['buckets']['sinpe']);
        $this->assertSame('1000.0000', $totals['total']);
        $this->assertSame(1, $totals['payments_count']);

        // Otro cobrador de la misma empresa no contamina los totales del día.
        [$collectorB, , $accountB] = $this->collectorWithAccount($c, $b, '3000.0000');
        $this->pay($c, $accountB, $collectorB, '700.00');

        $again = $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.closing.show'))->assertOk();
        $this->assertSame('1000.0000', $again->viewData('totals')['total']);
    }

    public function test_collector_submits_close_with_evidence_idempotently(): void
    {
        Storage::fake('local');
        [$c, $b] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '5000.0000');
        $this->pay($c, $account, $collector, '1000.00');

        $sessions = ['active_company_id' => $c->id];
        $response = $this->actingAs($collector)->withSession($sessions)
            ->post(route('cobros.closing.store'), [
                'declared_cash' => '900.0000',
                'declared_sinpe' => '100.0000',
                'declared_card' => '',
                'declared_bank_transfer' => '',
                'notes' => 'Falta el comprobante del banco.',
                'evidence_deposit' => UploadedFile::fake()->image('deposito.png', 60, 60),
            ]);
        $response->assertSessionHasNoErrors()->assertRedirect();

        $closing = CobrosDailyClosing::sole();
        $this->assertSame($collector->id, $closing->collector_user_id);
        $this->assertSame($c->id, $closing->company_id);
        $this->assertSame('900.0000', (string) $closing->declared_cash);
        $this->assertSame('100.0000', (string) $closing->declared_sinpe);
        $this->assertNotNull($closing->submitted_at);
        $this->assertNotNull($closing->evidence_deposit_path);
        $this->assertNull($closing->evidence_dataphone_path);
        $this->assertSame('1000.0000', $closing->computed_totals['total']);
        $this->assertSame('Falta el comprobante del banco.', $closing->notes);

        // Reenvío el mismo día: una sola fila, actualiza y conserva evidencia.
        $this->actingAs($collector)->withSession($sessions)
            ->post(route('cobros.closing.store'), [
                'declared_cash' => '1000.0000',
                'notes' => 'Rectificado.',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, CobrosDailyClosing::count());
        $closing->refresh();
        $this->assertSame('1000.0000', (string) $closing->declared_cash);
        $this->assertSame('Rectificado.', $closing->notes);
        $this->assertNotNull($closing->evidence_deposit_path);

        // Monto con más de 4 decimales: rechazado (escala exacta, sin round()).
        $this->actingAs($collector)->withSession($sessions)
            ->post(route('cobros.closing.store'), ['declared_cash' => '10.00005'])
            ->assertSessionHasErrors('declared_cash');
    }

    public function test_owner_drill_down_shows_closings_with_differences(): void
    {
        Storage::fake('local');
        [$c, $b, $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);
        [$collector, , $account] = $this->collectorWithAccount($c, $b, '5000.0000');
        $this->pay($c, $account, $collector, '1000.00');

        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.closing.store'), [
                'declared_cash' => '950.0000',
                'declared_bank_transfer' => '50.0000',
                'evidence_dataphone' => UploadedFile::fake()->image('dataphone.jpg', 60, 60),
            ])->assertSessionHasNoErrors();

        $response = $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.owner.collectors.show', $collector))
            ->assertOk()
            ->assertSee('Cierres diarios')
            ->assertSee('Con diferencias')
            ->assertSee('Medios de pago del mes')
            ->assertSee('Promesas de pago');

        $closings = $response->viewData('closings');
        $this->assertCount(1, $closings);
        $diff = $closings[0]['differences'];
        $this->assertTrue($diff['has_differences']);
        // Declarado 950 + 50 = 1000; sistema 1000 en efectivo → diferencia
        // concentrada en efectivo (-50) y depósito (+50).
        $this->assertSame('-50.0000', $diff['buckets']['cash']['difference']);
        $this->assertSame('50.0000', $diff['buckets']['bank_transfer']['difference']);
        $this->assertSame('0.0000', $diff['total']['difference']);

        $closing = $closings[0]['closing'];
        $this->assertNotNull($closing->evidence_dataphone_path);
        $this->assertTrue(Storage::disk('local')->exists($closing->evidence_dataphone_path));

        // El dueño registra su revisión con hora, usuario y nota.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.owner.closings.review', ['closing' => $closing]), [
                'review_notes' => 'Diferencia de 50.00 verificada contra el comprobante.',
            ])->assertSessionHasNoErrors();
        $closing->refresh();
        $this->assertNotNull($closing->reviewed_at);
        $this->assertSame($owner->id, $closing->reviewed_by_user_id);
        $this->assertSame('Diferencia de 50.00 verificada contra el comprobante.', $closing->review_notes);

        // El dueño sirve la evidencia; el cobrador dueño del cierre también.
        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.closing.evidence', ['closing' => $closing, 'kind' => 'dataphone']))
            ->assertOk();
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.closing.evidence', ['closing' => $closing, 'kind' => 'deposito']))
            ->assertNotFound();

        // Un cobrador ajeno de la misma empresa no puede ver la evidencia.
        $stranger = User::factory()->create(['is_active' => true]);
        $strangerRole = Role::create(['company_id' => $c->id, 'name' => 'Ajeno '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $name) {
            $strangerRole->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $stranger->companies()->attach($c->id, ['role_id' => $strangerRole->id]);
        $stranger->branches()->attach($b->id);
        $this->actingAs($stranger)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.closing.evidence', ['closing' => $closing, 'kind' => 'dataphone']))
            ->assertForbidden();
    }

    public function test_close_requires_the_collector_permission_and_stays_tenant_scoped(): void
    {
        [$c, , $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);

        $this->actingAs($owner)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.closing.show'))->assertForbidden();

        // Empresa distinta: su dueño no ve cierres ni evidencias de la primera.
        [, , $otherUser] = $this->context(['cobros.acceder', 'cobros.portal_dueno'], User::factory()->create(['is_active' => true]));
        $closing = CobrosDailyClosing::create([
            'company_id' => $c->id,
            'collector_user_id' => User::factory()->create(['is_active' => true])->id,
            'business_date' => now()->toDateString(),
            'computed_totals' => ['buckets' => [], 'total' => '0.0000', 'payments_count' => 0, 'reversals_count' => 0],
        ]);

        $this->actingAs($otherUser)->withSession(['active_company_id' => $otherUser->companies()->first()->id])
            ->get(route('cobros.closing.evidence', ['closing' => $closing, 'kind' => 'deposito']))
            ->assertNotFound();
    }

    private function pay(Company $c, AccountReceivable $account, User $collector, string $amount): void
    {
        $method = PaymentMethod::query()->where('company_id', $c->id)->where('code', 'cash')->firstOrFail();
        $this->actingAs($collector)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.store', $account),
            [
                'amount' => $amount, 'payment_method_id' => $method->id, 'reference' => null,
                'notes' => null, 'client_token' => (string) Str::uuid(), 'confirm' => '1',
            ]
        )->assertRedirect();

        // Ancla temporal determinista: el pago queda a las 12:00 del día de la
        // empresa, dentro de la ventana del cierre sin importar la hora real.
        AccountReceivablePayment::query()
            ->where('company_id', $c->id)
            ->where('user_id', $collector->id)
            ->update(['paid_at' => CarbonImmutable::today($c->timezone)->setTime(12, 0)]);
    }

    /** @return array{0: User, 1: Customer, 2: AccountReceivable} */
    private function collectorWithAccount(Company $c, Branch $b, string $balance): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $c->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($c->id, ['role_id' => $role->id]);
        $user->branches()->attach($b->id);

        $customer = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
        CobrosAssignment::create([
            'company_id' => $c->id, 'customer_id' => $customer->id,
            'collector_user_id' => $user->id, 'assigned_by' => $user->id, 'assigned_at' => now(),
        ]);
        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $user->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
        $account = AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays(7), 'original_amount' => $balance, 'balance_due' => $balance, 'status' => 'pending', 'currency_code' => 'CRC']);

        return [$user, $customer, $account];
    }

    /**
     * @return array{0: Company, 1: Branch, 2: User}
     */
    private function context(array $permissions = ['cobros.acceder', 'cobros.portal_dueno'], ?User $user = null): array
    {
        $company = Company::create(['trade_name' => 'Cobros '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'D'.random_int(1, 99), 'is_active' => true]);
        $user ??= User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Dueño '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);
        app(PaymentMethodProvisioner::class)->provision($company);

        return [$company->fresh(), $branch, $user];
    }
}
