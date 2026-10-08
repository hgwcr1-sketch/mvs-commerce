<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\Cobros\InstallmentScheduleService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * BLOQUE 9 — motor, aprobación idempotente y desembolso (CxC/cuotas/cartera).
 * Ejecutar esta clase POR SEPARADO (no combinar con otras de GD en un proceso).
 */
class CobrosCreditDisbursementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([], 404)]);
    }

    private function member(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    /** @return array{0: Company, 1: User, 2: CobrosRoute, 3: User} dueño en índice 3 */
    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Cred '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno', 'notificaciones.cobros']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Norte', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());

        // Sucursal activa: requisito técnico del esquema CxC Core (no autoriza nada).
        \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'S01']);

        return [$company, $collector, $route, $owner];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'applicant_type' => 'nuevo',
            'identification_type' => 'cedula',
            'identification' => '102030405',
            'name' => 'Maria Solis',
            'phone' => '88888888',
            'address' => 'San Ramon, calle 1',
            'amount_requested' => '1500.50',
            'notes' => 'Primera solicitud',
        ], $overrides);
    }

    private function sendAsCollector(array $payload, CobrosRoute $route, User $collector): TestResponse
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $route->company_id, 'active_cobros_route_id' => $route->id]);
        $review = $this->post(route('cobros.collector.credit-requests.review'), $payload + [
            'id_front' => UploadedFile::fake()->image('frente.jpg', 100, 80),
            'id_back' => UploadedFile::fake()->image('reverso.jpg', 100, 80),
        ]);
        $review->assertOk();
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->assertNotEmpty($m, 'token de revisión ausente');

        return $this->post(route('cobros.collector.credit-requests.store'), $payload + ['review_token' => $m[1]]);
    }

    /** Solicitud enviada + verificada por el dueño. */
    private function verifiedRequest(Company $company, User $owner, CobrosRoute $route, User $collector, array $overrides = []): CobrosCreditRequest
    {
        $this->sendAsCollector($this->payload($overrides), $route, $collector)->assertRedirect();
        $id = CobrosCreditRequest::forCompany($company->id)->orderByDesc('id')->firstOrFail()->id;

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.verify', $id), ['motivo' => 'Datos confirmados'])->assertRedirect();

        return CobrosCreditRequest::forCompany($company->id)->findOrFail($id);
    }

    private function terms(array $overrides = []): array
    {
        return array_merge([
            'principal' => '1000.00',
            'interest_type' => 'percent',
            'interest_value' => '12.5',
            'installment_count' => 4,
            'frequency' => 'monthly',
            'first_due_date' => now()->addWeek()->toDateString(),
            'late_fee_type' => 'none',
            'late_fee_value' => '0',
            'late_fee_grace_days' => 0,
        ], $overrides);
    }

    public function test_schedule_engine_interest_none_fixed_percent_and_exact_sums(): void
    {
        $schedule = app(InstallmentScheduleService::class);

        // Interés simple: none / fixed / percent (half-up 4 decimales).
        $this->assertSame('1000.0000', $schedule->totalWithInterest('1000', 'none', '0'));
        $this->assertSame('1150.0000', $schedule->totalWithInterest('1000', 'fixed', '150'));
        $this->assertSame('1125.0000', $schedule->totalWithInterest('1000', 'percent', '12.5'));
        // 333.33 * 7.77% = 25.899741 → 25.8997 → total 359.2297 (half-up 4).
        $this->assertSame('359.2297', $schedule->totalWithInterest('333.33', 'percent', '7.77'));

        // Suma EXACTA del calendario: base truncada, última cuota absorbe el residuo.
        $rows = $schedule->build('1000.0000', 3, 'monthly', '2026-03-15');
        $this->assertSame(['333.3333', '333.3333', '333.3334'], array_column($rows, 'contractual_amount'));
        $sum = array_reduce($rows, fn ($carry, $row) => bcadd($carry, $row['contractual_amount'], 4), '0.0000');
        $this->assertSame('1000.0000', $sum);

        // Cuota única = total completo.
        $single = $schedule->build('250.5000', 1, 'daily', '2026-03-15');
        $this->assertSame('250.5000', $single[0]['contractual_amount']);
    }

    public function test_calendar_frequencies_daily_weekly_biweekly_monthly_and_eom(): void
    {
        $schedule = app(InstallmentScheduleService::class);

        $daily = $schedule->build('30.0000', 3, 'daily', '2026-03-15');
        $this->assertSame(['2026-03-15', '2026-03-16', '2026-03-17'], array_column($daily, 'due_date'));

        $weekly = $schedule->build('40.0000', 3, 'weekly', '2026-03-15');
        $this->assertSame(['2026-03-15', '2026-03-22', '2026-03-29'], array_column($weekly, 'due_date'));

        $biweekly = $schedule->build('50.0000', 3, 'biweekly', '2026-03-15');
        $this->assertSame(['2026-03-15', '2026-03-29', '2026-04-12'], array_column($biweekly, 'due_date'));

        // Mensual sin overflow: 31 ene → 28 feb → 31 mar (EOM seguro).
        $monthly = $schedule->build('60.0000', 3, 'monthly', '2026-01-31');
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_column($monthly, 'due_date'));
    }

    public function test_preview_endpoint_uses_same_engine(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $response = $this->post(route('cobros.owner.creditos.preview'), $this->terms());

        $response->assertOk()->assertJsonPath('total_due', '1125.0000');
        $rows = $response->json('rows');
        $this->assertCount(4, $rows);
        $sum = array_reduce($rows, fn ($carry, $row) => bcadd($carry, $row['contractual_amount'], 4), '0.0000');
        $this->assertSame('1125.0000', $sum);
        $this->assertNotNull($request->id);
    }

    public function test_approve_is_idempotent_creates_no_cxc_and_request_becomes_aprobada(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        // Formulario de condiciones accesible SOLO con la solicitud verificada.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.creditos.define', $request->id))->assertOk()->assertSee('Preparar el préstamo');

        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms())
            ->assertRedirect();
        $this->assertDatabaseCount('cobros_credits', 1);
        $this->assertDatabaseCount('accounts_receivable', 0);   // Aprobar NO crea CxC.
        $this->assertDatabaseCount('cobros_credit_installments', 0); // NI cuotas.
        $this->assertSame(CobrosCredit::STATUS_APPROVED, CobrosCredit::first()->status);
        $this->assertSame('1125.0000', (string) CobrosCredit::first()->total_due);
        $this->assertSame(CobrosCreditRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertDatabaseHas('cobros_credit_request_logs', [
            'credit_request_id' => $request->id,
            'from_status' => CobrosCreditRequest::STATUS_VERIFIED,
            'to_status' => CobrosCreditRequest::STATUS_APPROVED,
        ]);

        // Idempotencia: la MISMA solicitud no genera un segundo crédito.
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms())
            ->assertRedirect();
        $this->assertDatabaseCount('cobros_credits', 1);
        $this->assertDatabaseCount('accounts_receivable', 0);
    }

    public function test_approve_requires_verified_request(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $id = CobrosCreditRequest::forCompany($company->id)->firstOrFail()->id; // pendiente

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $id), $this->terms())
            ->assertSessionHasErrors('transition');
        $this->assertDatabaseCount('cobros_credits', 0);
    }

    public function test_disburse_creates_exactly_one_cxc_calendar_assignment_and_freezes_snapshot(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms())->assertRedirect();
        $credit = CobrosCredit::firstOrFail();
        $snapshotBefore = $credit->terms_snapshot;

        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();
        $credit->refresh();

        $this->assertSame(CobrosCredit::STATUS_DISBURSED, $credit->status);
        $this->assertNotNull($credit->account_receivable_id);

        // Exactamente 1 CxC: total = snapshot, primera cuota = vencimiento, sin venta.
        $this->assertDatabaseCount('accounts_receivable', 1);
        $account = AccountReceivable::firstOrFail();
        $this->assertSame('1125.0000', (string) $account->original_amount);
        $this->assertSame('1125.0000', (string) $account->balance_due);
        $this->assertSame($credit->first_due_date->toDateString(), $account->due_date->toDateString());
        $this->assertNull($account->sale_id);
        $this->assertSame($route->id, (int) $account->cobros_route_id);

        // Calendario: N cuotas, suma exacta = total.
        $this->assertSame(4, CobrosCreditInstallment::where('credit_id', $credit->id)->count());
        $sum = CobrosCreditInstallment::where('credit_id', $credit->id)
            ->pluck('contractual_amount')
            ->reduce(fn ($carry, $amount) => bcadd($carry, (string) $amount, 4), '0.0000');
        $this->assertSame('1125.0000', $sum);

        // Cartera: assignment cliente+cobrador+ruta.
        $this->assertDatabaseHas('cobros_assignments', [
            'company_id' => $company->id,
            'customer_id' => $credit->customer_id,
            'collector_user_id' => $collector->id,
        ]);

        // Snapshot congelado: idéntico tras desembolsar.
        $this->assertEquals($snapshotBefore, $credit->fresh()->terms_snapshot);
        $this->assertSame('1000.0000', (string) $credit->principal);

        // Reintento de desembolso: NO duplica nada.
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();
        $this->assertDatabaseCount('cobros_credits', 1);
        $this->assertDatabaseCount('accounts_receivable', 1);
        $this->assertDatabaseCount('cobros_credit_installments', 4);
    }

    public function test_disburse_resolves_new_and_existing_customer(): void
    {
        [$company, $collector, $route, $owner] = $this->context();

        // (a) existente: el vínculo ya viene de la solicitud.
        $existing = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'persona',
            'identification_type' => 'cedula',
            'identification' => '300400500',
            'name' => 'Cliente Existente',
        ]);
        $requestA = $this->verifiedRequest($company, $owner, $route, $collector, [
            'applicant_type' => 'existente',
            'customer_id' => $existing->id,
            'identification' => '300400500',
            'name' => 'Cliente Existente',
        ]);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $requestA->id), $this->terms())->assertRedirect();
        $creditA = CobrosCredit::firstOrFail();
        $this->post(route('cobros.owner.creditos.disburse', $creditA->id), ['signature' => $this->signaturePng()])->assertRedirect();
        $this->assertSame($existing->id, (int) CobrosCredit::first()->customer_id);
        $this->assertDatabaseCount('customers', 1);

        // (b) nuevo: se crea UNA vez aquí y queda vinculado a la solicitud.
        $requestB = $this->verifiedRequest($company, $owner, $route, $collector, [
            'identification' => '556677889',
            'name' => 'Persona Nueva',
        ]);
        $this->assertNull($requestB->customer_id);

        $this->post(route('cobros.owner.creditos.approve', $requestB->id), $this->terms())->assertRedirect();
        $creditB = CobrosCredit::where('credit_request_id', $requestB->id)->firstOrFail();
        $this->post(route('cobros.owner.creditos.disburse', $creditB->id), ['signature' => $this->signaturePng()])->assertRedirect();

        $creditB->refresh();
        $this->assertNotNull($creditB->customer_id);
        $this->assertSame('556677889', (string) Customer::whereKey($creditB->customer_id)->firstOrFail()->identification);
        $this->assertSame($creditB->customer_id, (int) $requestB->fresh()->customer_id);
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_security_collector_cannot_approve_or_disburse_and_foreign_tenant_is_404(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        // El cobrador NUNCA aprueba ni desembolsa (solo portal_dueno).
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.owner.creditos.define', $request->id))->assertForbidden();
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms())->assertForbidden();

        // Dueño aprueba → el crédito existe y el cobrador no puede desembolsarlo.
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms())->assertRedirect();
        $credit = CobrosCredit::firstOrFail();

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.owner.creditos.show', $credit->id))->assertForbidden();
        $this->post(route('cobros.owner.creditos.disburse', $credit->id))->assertForbidden();

        // Otro tenant: solicitud y crédito ajenos = 404 (IDOR), jamás 200/403.
        session()->forget('active_company_id');
        [$otherCompany] = $this->context();
        $foreignOwner = $this->member($otherCompany, ['cobros.acceder', 'cobros.portal_dueno']);
        $this->actingAs($foreignOwner)->withSession(['active_company_id' => $otherCompany->id]);
        $this->get(route('cobros.owner.creditos.define', $request->id))->assertNotFound();
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms())->assertNotFound();
        $this->get(route('cobros.owner.creditos.show', $credit->id))->assertNotFound();
        $this->post(route('cobros.owner.creditos.disburse', $credit->id))->assertNotFound();
    }

    /** UX 9A: lenguaje cotidiano, campos opcionales OCULTOS por defecto y confirmación presente. */
    public function test_define_ux_plain_language_hidden_optional_fields_and_confirmation_copy(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $html = $this->get(route('cobros.owner.creditos.define', $request->id))->assertOk()->getContent();

        // Título/subtítulo en lenguaje cotidiano + monto solicitado visible y no editable.
        $this->assertStringContainsString('Preparar el préstamo', $html);
        $this->assertStringContainsString('Revise cuánto va a prestar y cómo pagará el cliente.', $html);
        $this->assertStringContainsString('Maria Solis', $html);
        $this->assertStringContainsString('solo lectura', $html);
        $this->assertMatchesRegularExpression('/name="principal"[^>]*value="1500\.50"/', $html);

        // Sin interés / sin mora: valor y gracia OCULTOS (hidden) y sin required.
        $this->assertMatchesRegularExpression('/id="interest-value-block"[^>]*\shidden/', $html);
        $this->assertMatchesRegularExpression('/id="latefee-value-block"[^>]*\shidden/', $html);
        $this->assertMatchesRegularExpression('/id="latefee-grace-block"[^>]*\shidden/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="interest_value"[^>]*\srequired/', $html);

        // Etiquetas correctas por tipo de interés/mora (configuradas por el JS).
        $this->assertStringContainsString('Porcentaje de interés', $html);
        $this->assertStringContainsString('Ejemplo: 20 significa 20% sobre el monto prestado.', $html);
        $this->assertStringContainsString('Interés total', $html);
        $this->assertStringContainsString('Monto total que cobrará de interés por este préstamo.', $html);
        $this->assertStringContainsString('Durante estos días puede pagar sin que se le cobre mora.', $html);

        // Resumen + calendario + botón y confirmación con monto / pagos / total.
        $this->assertStringContainsString('Resumen', $html);
        $this->assertStringContainsString('TOTAL A COBRAR', $html);
        $this->assertStringContainsString('Calendario de pagos', $html);
        $this->assertStringContainsString('REVISAR Y AUTORIZAR PRÉSTAMO', $html);
        $this->assertStringContainsString('Volver y revisar', $html);
        $this->assertStringContainsString('CONFIRMAR AUTORIZACIÓN', $html);
        $this->assertStringContainsString('Está por aprobar un préstamo de', $html);
        $this->assertStringContainsString('pagos', $html);
        $this->assertStringContainsString('Total a cobrar', $html);
        $this->assertStringContainsString('estas condiciones quedan fijas', $html);
    }

    /** Sin interés y sin mora: el servidor NO exige esos valores. */
    public function test_approve_without_interest_and_without_mora_does_not_require_those_values(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $terms = $this->terms(['interest_type' => 'none']);
        unset($terms['interest_value'], $terms['late_fee_value'], $terms['late_fee_grace_days']);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $request->id), $terms)->assertRedirect();

        $credit = CobrosCredit::firstOrFail();
        $this->assertSame('0.0000', (string) $credit->interest_value);
        $this->assertSame('1000.0000', (string) $credit->total_due); // sin interés
        $this->assertSame(0, (int) $credit->late_fee_grace_days);
        $this->assertDatabaseCount('accounts_receivable', 0);
    }

    /** Con interés y con mora: sí se exige el valor, con error en español junto al campo. */
    public function test_approve_requires_interest_value_for_percent_and_grace_days_when_mora_applies(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $missingInterest = $this->terms(['interest_type' => 'percent']);
        unset($missingInterest['interest_value']);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $request->id), $missingInterest)
            ->assertSessionHasErrors('interest_value');
        $this->assertDatabaseCount('cobros_credits', 0);

        $missingGrace = $this->terms(['late_fee_type' => 'fixed', 'late_fee_value' => '500']);
        unset($missingGrace['late_fee_grace_days']);
        $this->post(route('cobros.owner.creditos.approve', $request->id), $missingGrace)
            ->assertSessionHasErrors('late_fee_grace_days');
        $this->assertDatabaseCount('cobros_credits', 0);

        // Con mora completa sí aprueba.
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms([
            'late_fee_type' => 'fixed', 'late_fee_value' => '500', 'late_fee_grace_days' => 3,
        ]))->assertRedirect();
        $this->assertDatabaseCount('cobros_credits', 1);
    }

    /** El resumen/confirmación se alimenta EXACTAMENTE del motor (no de cálculos JS). */
    public function test_preview_returns_exact_engine_summary_used_by_confirmation(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->verifiedRequest($company, $owner, $route, $collector);

        $terms = $this->terms(['interest_type' => 'percent', 'interest_value' => '12.5', 'installment_count' => 4]);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $response = $this->post(route('cobros.owner.creditos.preview'), $terms)->assertOk();

        $payload = $response->json();
        $this->assertSame('1000.0000', $payload['principal']);
        $this->assertSame('1125.0000', $payload['total_due']);
        $this->assertSame('125.0000', $payload['interest']);        // total − principal (BCMath)
        $this->assertSame(4, $payload['installment_count']);
        $this->assertSame('281.2500', $payload['normal_amount']);
        $this->assertSame('281.2500', $payload['last_amount']);
        $this->assertCount(4, $payload['rows']);

        $sum = array_reduce($payload['rows'], fn ($carry, $row) => bcadd($carry, $row['contractual_amount'], 4), '0.0000');
        $this->assertSame($payload['total_due'], $sum);
    }

    /** Firma PNG válida (lienzo con tinta) en el formato que espera la entrega. */
    protected function signaturePng(): string
    {
        $image = imagecreatetruecolor(400, 120);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        $ink = imagecolorallocate($image, 15, 23, 42);
        imagesetthickness($image, 3);
        imageline($image, 20, 80, 200, 40, $ink);
        imageline($image, 200, 40, 380, 90, $ink);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
