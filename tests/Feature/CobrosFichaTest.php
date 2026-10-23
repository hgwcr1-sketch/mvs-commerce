<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\CobrosReceipt;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use App\Support\Cobros\WhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ficha de Mi Cartera como centro de trabajo SIMPLE del cobrador (6B).
 *
 * Reglas certificadas aquí:
 * - identidad grande, cédula/teléfono visibles, LLAMAR/WHATSAPP y saldo ₡#,##0.00;
 * - ➕ AGREGAR TELÉFONO reutiliza el MISMO flujo/campo del recibo;
 * - gestión rápida reutiliza 2B (estados, validaciones y bitácora, sin dinero);
 * - aislamiento tenant + cartera asignada + permission:cobros.cobrar (sin IDOR).
 */
class CobrosFichaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ficha_shows_identity_contact_saldo_and_primary_actions(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '1500.0000', [
            'identification' => '3012345678',
            'phone_country_code' => '506',
            'mobile' => '8888 1234',
        ]);
        $customer->update(['latitude' => '9.931', 'longitude' => '-84.082']);

        $html = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $customer))
            ->assertOk()->getContent();

        foreach ([
            $customer->name,
            '3012345678',
            '8888 1234',
            'href="tel:88881234"',
            '📞 LLAMAR',
            'wa.me/50688881234',
            '🟢 WHATSAPP',
            'Saldo total',
            '₡1,500.00',
            '💰 COBRAR',
            '🧭 IR AL CLIENTE',
            'Gestión rápida',
            '✅ VISITADO',
            '🚪 NO ESTABA',
            '🤝 PROMESA',
            '📅 OTRO DÍA',
            route('cobros.collector.payments.create', $account->id),
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, 'Falta: '.$expected);
        }

        // Sin IDs internos ni estados técnicos.
        $this->assertStringNotContainsString('CxC #', $html);
    }

    public function test_ficha_with_credit_schedule_resolves_next_due_without_unknown_column(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer] = $this->customerWithAccount($c, $b, $u, '1500.0000');
        $route = CobrosRoute::create(['company_id' => $c->id, 'name' => 'Ruta ficha '.uniqid(), 'active' => true]);
        $request = CobrosCreditRequest::create([
            'company_id' => $c->id,
            'cobros_route_id' => $route->id,
            'collector_user_id' => $u->id,
            'customer_id' => $customer->id,
            'applicant_type' => 'existente',
            'status' => CobrosCreditRequest::STATUS_APPROVED,
            'identification_type' => 'cedula',
            'identification' => 'FICHA-'.uniqid(),
            'name' => $customer->name,
            'amount_requested' => '1200.0000',
        ]);
        $credit = CobrosCredit::create([
            'company_id' => $c->id,
            'credit_request_id' => $request->id,
            'cobros_route_id' => $route->id,
            'customer_id' => $customer->id,
            'collector_user_id' => $u->id,
            'principal' => '1200.0000',
            'interest_type' => CobrosCredit::INTEREST_NONE,
            'interest_value' => '0.0000',
            'total_due' => '1200.0000',
            'installment_count' => 1,
            'frequency' => CobrosCredit::FREQUENCY_MONTHLY,
            'disbursement_date' => today(),
            'first_due_date' => today()->addMonth(),
            'late_fee_type' => CobrosCredit::LATE_FEE_NONE,
            'late_fee_value' => '0.0000',
            'late_fee_grace_days' => 0,
            'status' => CobrosCredit::STATUS_DISBURSED,
            'terms_snapshot' => ['principal' => '1200.0000', 'total_due' => '1200.0000'],
            'approved_at' => now(),
            'disbursed_at' => now(),
        ]);
        CobrosCreditInstallment::create([
            'credit_id' => $credit->id,
            'number' => 1,
            'due_date' => today()->addMonth(),
            'contractual_amount' => '1200.0000',
            'applied_amount' => '0.0000',
            'status' => CobrosCreditInstallment::STATUS_PENDING,
        ]);

        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $customer))
            ->assertOk()
            ->assertViewHas('credits', fn ($credits) => $credits->first()->next_due_date?->isSameDay(today()->addMonth()));
    }

    public function test_ficha_without_identification_or_phone_enables_whatsapp_after_saving(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '500.0000');

        $before = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $customer))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Identificación pendiente', $before);
        $this->assertStringContainsString('AGREGAR TELÉFONO', $before);
        $this->assertStringContainsString('inputmode="tel"', $before);
        $this->assertStringNotContainsString('wa.me/', $before);

        // Mismo endpoint/campo que el recibo (reutilización exacta, sin duplicar lógica).
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.customer.phone', $customer),
            ['phone' => '+50688881234']
        )->assertRedirect();
        $this->assertSame('50688881234', $customer->fresh()->phone);

        $after = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $customer))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#wa\.me/50688881234#', $after);
        $this->assertStringContainsString('🟢 WHATSAPP', $after);
        $this->assertStringNotContainsString('AGREGAR TELÉFONO', $after);
    }

    public function test_phone_normalization_local_prefixed_and_separated_without_duplicating_country(): void
    {
        [$c] = $this->context();

        $mk = fn (?string $phone, ?string $cc = null): Customer => Customer::create([
            'company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Tel '.uniqid(),
            'phone' => $phone, 'phone_country_code' => $cc, 'is_active' => true,
        ]);

        // Local CR (8 dígitos): 50688133806 sin alterar el dato almacenado.
        $local = $mk('88133806');
        $this->assertSame('50688133806', WhatsApp::phone($c, $local));
        $this->assertSame('88133806', $local->fresh()->phone);

        // Ya prefijado: jamás duplicar 506.
        foreach (['+50688133806', '50688133806'] as $prefixed) {
            $this->assertSame('50688133806', WhatsApp::phone($c, $mk($prefixed)), $prefixed);
        }

        // Separadores (espacios/guiones/paréntesis) normalizados.
        $this->assertSame('50688133806', WhatsApp::phone($c, $mk('8813-3806')));
        $this->assertSame('50688133806', WhatsApp::phone($c, $mk('(506) 8813 3806')));

        // Teléfono ausente => null (la UI ofrece AGREGAR TELÉFONO).
        $this->assertNull(WhatsApp::phone($c, $mk(null)));
        $this->assertNull(WhatsApp::phone($c, $mk('   ')));
    }

    public function test_ficha_local_phone_shows_llamar_whatsapp_without_agregar(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer] = $this->customerWithAccount($c, $b, $u, '100.0000', ['phone' => '88133806']);

        $html = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $customer))
            ->assertOk()->getContent();

        $this->assertStringContainsString('href="tel:88133806"', $html);
        $this->assertStringContainsString('wa.me/50688133806', $html);
        $this->assertStringContainsString('📞 LLAMAR', $html);
        $this->assertStringContainsString('🟢 WHATSAPP', $html);
        $this->assertStringNotContainsString('AGREGAR TELÉFONO', $html);

        // Regresión: sin teléfono real, sí se ofrece AGREGAR TELÉFONO (sin enlaces rotos).
        [$sinTel] = $this->customerWithAccount($c, $b, $u, '50.0000');
        $sinTelHtml = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $sinTel))
            ->assertOk()->getContent();
        $this->assertStringContainsString('AGREGAR TELÉFONO', $sinTelHtml);
        $this->assertStringNotContainsString('wa.me/', $sinTelHtml);
    }

    public function test_gestion_rapida_reuses_2b_states_audit_and_touches_no_money(): void
    {
        [$c, $b, $u] = $this->context();
        [$customer, $account] = $this->customerWithAccount($c, $b, $u, '800.0000');

        // ✅ VISITADO sin visita previa: crea la de hoy (schedule) + bitácora (record).
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.visits.manage', $customer),
            ['status' => 'visitado']
        )->assertRedirect();

        $visit = CobrosVisit::latest('id')->first();
        $this->assertSame('visitado', $visit->status);
        $this->assertSame($customer->id, $visit->customer_id);
        $this->assertSame($u->id, $visit->collector_user_id);
        $this->assertDatabaseCount('cobros_visit_logs', 2); // creación + transición

        // 🤝 PROMESA: solo lo que 2B exige (fecha obligatoria).
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.visits.manage', $customer),
            ['status' => 'promesa_pago']
        )->assertSessionHasErrors('promise_date');

        $promiseDate = now($c->timezone)->addDays(5)->toDateString();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.visits.manage', $customer),
            ['status' => 'promesa_pago', 'promise_date' => $promiseDate]
        )->assertRedirect();

        $promised = CobrosVisit::where('status', 'promesa_pago')->latest('id')->first();
        $this->assertSame($promiseDate, $promised->promise_date->toDateString());

        // 📅 OTRO DÍA: reutiliza la reprogramación trazada de 2B.
        $newDate = now($c->timezone)->addDays(3)->toDateString();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.visits.manage', $customer),
            ['status' => 'reprogramada', 'scheduled_for' => $newDate]
        )->assertRedirect();

        $this->assertDatabaseHas('cobros_visits', ['status' => 'reprogramada']);
        $this->assertTrue(CobrosVisit::query()
            ->where('customer_id', $customer->id)
            ->where('collector_user_id', $u->id)
            ->where('status', 'pendiente')
            ->whereDate('scheduled_for', $newDate)
            ->exists(), 'La reprogramación nace una visita pendiente con la nueva fecha.');

        // 2B nunca toca dinero.
        $this->assertSame('800.0000', $account->fresh()->balance_due);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
    }

    public function test_ficha_manage_phone_are_isolated_by_tenant_portfolio_and_permission(): void
    {
        [$c, $b, $u] = $this->context();
        [$mine, $mineAccount] = $this->customerWithAccount($c, $b, $u, '500.0000');
        $unassigned = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Libre '.uniqid(), 'is_active' => true]);
        [$foreignCompany, $foreignBranch, $foreignUser] = $this->context();
        [$foreign, $foreignAccount] = $this->customerWithAccount($foreignCompany, $foreignBranch, $foreignUser, '700.0000');

        // Ficha: cliente ajeno o sin asignar => 404.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $foreign))->assertNotFound();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.collector.portfolio.show', $unassigned))->assertNotFound();

        // Gestión rápida: mismo criterio y sin escrituras.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.manage', $foreign), ['status' => 'visitado'])->assertNotFound();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.visits.manage', $unassigned), ['status' => 'visitado'])->assertNotFound();
        $this->assertDatabaseCount('cobros_visits', 0);

        // Teléfono: mismo criterio y sin escrituras.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.customer.phone', $foreign), ['phone' => '+50688881234'])->assertNotFound();
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.customer.phone', $unassigned), ['phone' => '+50688881234'])->assertNotFound();
        $this->assertNull($foreign->fresh()->phone);
        $this->assertNull($unassigned->fresh()->phone);

        // Visita ajena (IDOR sobre 2B) => 404.
        $foreignVisit = CobrosVisit::create([
            'company_id' => $foreignCompany->id,
            'branch_id' => null,
            'customer_id' => $foreign->id,
            'collector_user_id' => $foreignUser->id,
            'scheduled_for' => now($foreignCompany->timezone)->toDateString(),
            'status' => 'pendiente',
        ]);
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->patch(route('cobros.visits.update', $foreignVisit), ['status' => 'visitado'])->assertNotFound();

        // Sin permiso cobros.cobrar => 403 (misma regla de la ficha).
        [$c3, $b3, $noPerm] = $this->context(['cobros.acceder']);
        $this->actingAs($noPerm)->withSession(['active_company_id' => $c3->id])
            ->post(route('cobros.visits.manage', $mine), ['status' => 'visitado'])->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Helpers

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'Ficha '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'F'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);
        app(PaymentMethodProvisioner::class)->provision($company);

        return [$company->fresh(), $branch, $user];
    }

    /** @return array{0: Customer, 1: AccountReceivable} */
    private function customerWithAccount(Company $c, Branch $b, User $u, string $balance, array $customerData = []): array
    {
        $customer = Customer::create([
            'company_id' => $c->id,
            'customer_type' => 'individual',
            'name' => $customerData['name'] ?? 'Cliente '.uniqid(),
            'identification' => $customerData['identification'] ?? null,
            'phone_country_code' => $customerData['phone_country_code'] ?? null,
            'phone' => $customerData['phone'] ?? null,
            'mobile' => $customerData['mobile'] ?? null,
            'is_active' => true,
        ]);
        CobrosAssignment::create([
            'company_id' => $c->id,
            'customer_id' => $customer->id,
            'collector_user_id' => $u->id,
            'assigned_by' => $u->id,
            'assigned_at' => now(),
        ]);

        $sale = Sale::create([
            'company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $u->id, 'customer_id' => $customer->id,
            'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now(),
        ]);
        $account = AccountReceivable::create([
            'company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id,
            'issued_at' => today()->subMonth(), 'due_date' => today($c->timezone)->addDays(7),
            'original_amount' => $balance, 'balance_due' => $balance, 'status' => 'pending', 'currency_code' => 'CRC',
        ]);

        return [$customer, $account];
    }
}
