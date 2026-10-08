<?php

namespace Tests\Feature;

use App\Models\CobrosCredit;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Calendario de PREPARAR PRÉSTAMO: la vista previa y la vista usan el MISMO
 * motor 9A (InstallmentScheduleService). Caso Ligia: 200.000 + 20% = 240.000
 * en 10 pagos semanales desde el primer pago. Ejecutar POR SEPARADO.
 */
class CobrosCreditPreviewScheduleTest extends TestCase
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

    /** @return array{0: Company, 1: User, 2: CobrosRoute, 3: User} */
    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Sched '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno', 'notificaciones.cobros']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'San Ramon', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());
        \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'S01']);

        return [$company, $collector, $route, $owner];
    }

    private function verifiedRequest(Company $company, User $owner, CobrosRoute $route, User $collector): CobrosCreditRequest
    {
        $payload = [
            'applicant_type' => 'nuevo',
            'identification_type' => 'cedula',
            'identification' => '102030405',
            'name' => 'Ligia Jacqueline Leiton Carranza',
            'phone' => '88888888',
            'address' => 'San Ramon, calle 1',
            'amount_requested' => '200000',
            'notes' => 'Solicitud',
        ];
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $review = $this->post(route('cobros.collector.credit-requests.review'), $payload + [
            'id_front' => UploadedFile::fake()->image('frente.jpg', 100, 80),
            'id_back' => UploadedFile::fake()->image('reverso.jpg', 100, 80),
        ]);
        $review->assertOk();
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->post(route('cobros.collector.credit-requests.store'), $payload + ['review_token' => $m[1]])->assertRedirect();

        $request = CobrosCreditRequest::forCompany($company->id)->orderByDesc('id')->firstOrFail();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.verify', $request->id), ['motivo' => 'Datos revisados'])->assertRedirect();

        return CobrosCreditRequest::forCompany($company->id)->findOrFail($request->id);
    }

    private function firstDue(): string
    {
        return now()->lte('2026-10-13') ? '2026-10-13' : now()->addDays(7)->toDateString();
    }

    /** Términos del caso Ligia: 200.000 + 20% = 240.000 en 10 semanales. */
    private function ligiaTerms(): array
    {
        return [
            'principal' => '200000',
            'interest_type' => 'percent',
            'interest_value' => '20',
            'installment_count' => 10,
            'frequency' => 'weekly',
            'first_due_date' => $this->firstDue(),
            'late_fee_type' => 'none',
            'late_fee_value' => '0',
            'late_fee_grace_days' => 0,
        ];
    }

    /** 11: el preview devuelve el calendario completo desde el motor 9A. */
    public function test_preview_returns_full_schedule_from_engine(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->verifiedRequest($company, $owner, $route, $collector);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $response = $this->post(route('cobros.owner.creditos.preview'), $this->ligiaTerms())
            ->assertOk()
            ->assertJsonStructure(['principal', 'total_due', 'interest', 'installment_count', 'normal_amount', 'last_amount', 'rows']);

        $this->assertCount(10, $response->json('rows'), '11-calendario-completo');
        $this->assertSame(10, $response->json('installment_count'));
        $this->assertSame('240000.0000', $response->json('total_due'));
        $this->assertSame('24000.0000', $response->json('normal_amount'));
    }

    /** 12 + 13 + 14: 10 fechas/montos exactos; suma = 240.000; primera fecha. */
    public function test_schedule_has_ten_rows_that_sum_to_total_and_start_on_first_payment(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $this->verifiedRequest($company, $owner, $route, $collector);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $rows = $this->post(route('cobros.owner.creditos.preview'), $this->ligiaTerms())
            ->assertOk()->json('rows');

        // 12) exactamente 10 filas numeradas 1..10 con fecha y monto.
        $this->assertCount(10, $rows);
        $sum = '0.0000';
        foreach ($rows as $index => $row) {
            $this->assertSame($index + 1, $row['number']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['due_date']);
            $this->assertArrayHasKey('contractual_amount', $row);
            $sum = bcadd($sum, $row['contractual_amount'], 4);
        }

        // 13) la suma del calendario = total ₡240.000,00.
        $this->assertSame('240000.0000', $sum, '13-suma-calendario-240000');

        // 14) primera fecha = 13/10/2026 (o el primer pago válido vigente).
        $this->assertSame($this->firstDue(), $rows[0]['due_date']);
        if ($this->firstDue() === '2026-10-13') {
            $this->assertSame('13/10/2026', Carbon::parse($rows[0]['due_date'])->format('d/m/Y'));
        }

        // Semanales: 7 días de diferencia entre fechas consecutivas.
        $this->assertSame(7, (int) Carbon::parse($rows[0]['due_date'])->diffInDays(Carbon::parse($rows[1]['due_date'])));
    }

    /** 15: la vista PREPARAR PRÉSTAMO renderiza las cuotas ANTES de autorizar (server-side por el motor). */
    public function test_prepare_view_renders_schedule_before_authorization(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.owner.creditos.define', $request->id))
            ->assertOk()
            ->assertSee('Calendario de pagos')
            ->assertSee('N.º de pago')
            // Cuotas renderizadas por el motor con los defaults del formulario:
            // el placeholder vacío ya NO aparece.
            ->assertDontSee('Complete los datos para ver el calendario')
            ->assertSee(now()->addWeek()->format('d/m/Y'))
            ->assertSee('Pago #1');
    }

    /** 16: el calendario mostrado coincide con el snapshot/motor que se autoriza. */
    public function test_displayed_schedule_matches_the_approved_engine_snapshot(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $rows = $this->post(route('cobros.owner.creditos.preview'), $this->ligiaTerms())
            ->assertOk()->json('rows');

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->ligiaTerms())
            ->assertRedirect();
        $credit = CobrosCredit::firstOrFail();
        $this->assertSame('240000.0000', (string) $credit->terms_snapshot['total_due']);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $show = $this->get(route('cobros.owner.creditos.show', $credit->id))->assertOk();

        // Cada fecha del calendario preview coincide con lo autorizado.
        foreach ($rows as $row) {
            $show->assertSee(Carbon::parse($row['due_date'])->format('d/m/Y'));
        }
    }

    /** 15b: HTML real del navegador — token CSRF en el form + header en el fetch
     *  del preview (el 419 real dejaba el calendario sin actualizar) y la marca
     *  `confirmed` antes de requestSubmit (sin ella CONFIRMAR reabría el diálogo). */
    public function test_define_html_contains_csrf_and_confirmed_flag_for_real_browser(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $html = $this->get(route('cobros.owner.creditos.define', $request->id))
            ->assertOk()->getContent();

        // Form real del navegador: POST + action correcta + CSRF presente.
        $this->assertStringContainsString('method="POST" action="'.route('cobros.owner.creditos.approve', $request->id).'" id="credit-form"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertMatchesRegularExpression('/type="submit" id="approve-btn"/', $html);

        // El fetch del preview envía el token (el navegador exige CSRF; los
        // tests unitarios lo bypasean, por eso nunca reproducían el fallo).
        $this->assertStringContainsString("'X-CSRF-TOKEN': csrfToken", $html);

        // El confirm marca confirmed ANTES de reenviar el form (evita el bucle
        // preventDefault + showModal que dejaba CONFIRMAR sin efecto).
        $this->assertMatchesRegularExpression('/confirmed = true;\s*if \(typeof form\.requestSubmit/', $html);

        // Botones del diálogo: type=button (no disparan submit por sí solos).
        $this->assertStringContainsString('<button type="button" id="confirm-yes"', $html);
        $this->assertStringContainsString('<button type="button" id="confirm-back"', $html);
    }
}
