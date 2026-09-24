<?php

namespace Tests\Feature;

use App\Models\CobrosVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosVisitRenderTest extends TestCase
{
    use RefreshDatabase;

    private function contextWithCustomer(): array
    {
        $company = \App\Models\Company::create(['trade_name' => 'Render '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Sucursal', 'code' => 'R'.random_int(1, 99), 'is_active' => true]);
        $user = \App\Models\User::factory()->create(['is_active' => true]);
        $role = \App\Models\Role::create(['company_id' => $company->id, 'name' => 'Cobrador '.uniqid(), 'is_active' => true]);
        foreach (['cobros.acceder', 'cobros.cobrar'] as $name) {
            $permission = \App\Models\Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(\App\Services\CompanyLicenseService::class)->updateModules($company, \App\Models\User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);

        $customer = \App\Models\Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Render Cliente '.uniqid(), 'is_active' => true]);
        app(\App\Services\Cobros\PortfolioAssignmentService::class)->assign($company, $customer, $user, \App\Models\User::factory()->create(['is_platform_admin' => true, 'is_active' => true]));

        return [$company->fresh(), $user, $customer];
    }

    private function scheduleVisit($company, $user, $customer, string $status, array $extra = []): CobrosVisit
    {
        // Programar siempre crea pendiente; estados de gestión se aplican solo vía Agenda (update).
        $this->actingAs($user)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.visits.store', $customer), ['scheduled_for' => today($company->timezone)->toDateString()] + $extra)
            ->assertSessionHasNoErrors();

        $visit = CobrosVisit::query()->where('customer_id', $customer->id)->orderByDesc('id')->firstOrFail();

        if ($status !== CobrosVisit::STATUS_PENDING) {
            $payload = ['status' => $status];
            if ($status === CobrosVisit::STATUS_PROMISE) {
                $payload['promise_date'] = today($company->timezone)->addDays(3)->toDateString();
            }
            $this->actingAs($user)->withSession(['active_company_id' => $company->id])
                ->patch(route('cobros.visits.update', $visit), $payload)
                ->assertSessionHasNoErrors();
            $visit = $visit->fresh();
        }

        return $visit;
    }

    public function test_history_schedule_form_only_shows_date_and_notes(): void
    {
        [$c, $u, $customer] = $this->contextWithCustomer();

        $content = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.customer', $customer))->getContent();

        $this->assertStringContainsString('Programar visita', $content);
        $this->assertStringContainsString('name="scheduled_for"', $content);
        $this->assertStringContainsString('name="notes"', $content);
        // UX simple del cobrador: sin estado/promesa en el formulario de programación.
        $this->assertStringNotContainsString('name="status"', $content);
        $this->assertStringNotContainsString('name="promise_date"', $content);
        $this->assertStringNotContainsString('name="promise_amount"', $content);
    }

    public function test_agenda_renders_four_clear_action_buttons_for_pending_visit(): void
    {
        [$c, $u, $customer] = $this->contextWithCustomer();
        $this->scheduleVisit($c, $u, $customer, 'pendiente');

        $response = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.today'))->assertOk();

        // Botones humanos y reconocibles, no campos blancos.
        foreach (['✓ Visitado', '✕ No estaba', '🤝 Promesa', '📅 Otro día'] as $label) {
            $response->assertSee($label, false);
        }
        // Clases de botón sólido (identidad CobrOS / contraste), nunca estilo input vacío.
        $this->assertStringContainsString('bg-green-600', $response->getContent());
        $this->assertStringContainsString('bg-primary', $response->getContent());
        $this->assertStringContainsString('bg-sky-700', $response->getContent());
    }

    public function test_agenda_never_leaks_raw_blade_expressions_as_text(): void
    {
        [$c, $u, $customer] = $this->contextWithCustomer();
        $this->scheduleVisit($c, $u, $customer, 'pendiente');

        $content = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.today'))->getContent();

        // Regresión del defecto detectado manualmente: expresiones Blade crudas.
        $this->assertStringNotContainsString('status === ', $content);
        $this->assertStringNotContainsString('? true : false)', $content);
        $this->assertStringNotContainsString('@open(', $content);
        $this->assertStringNotContainsString('@php', $content);
        $this->assertStringNotContainsString('@endphp', $content);
    }

    public function test_closed_visit_shows_human_label_and_no_action_forms(): void
    {
        [$c, $u, $customer] = $this->contextWithCustomer();
        $this->scheduleVisit($c, $u, $customer, 'no_localizado');

        $response = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.today'))->assertOk();
        $content = $response->getContent();

        // Etiqueta humana, nunca el código técnico.
        $this->assertStringContainsString('No estaba', $content);
        $this->assertStringNotContainsString('no_localizado</span>', $content);
        $this->assertStringNotContainsString('ucfirst', $content);

        // Gestión cerrada: sin botones de decisión ni formularios de promesa/reprogramación.
        $this->assertStringNotContainsString('✓ Visitado', $content);
        $this->assertStringNotContainsString('data-panel="promesa_pago" class="mt-3 hidden', $content . str_replace('data-action="visitado"', '', ''));
        // Los paneles ocultos solo se renderizan para visitas pendientes (dentro del bloque ! $done).
        $this->assertSame(0, substr_count($content, 'data-action="promesa_pago"'));
        // Historial sigue accesible.
        $this->assertStringContainsString('Historial del cliente', $content);
    }

    public function test_promise_and_reschedule_fields_are_mutually_exclusive_and_hidden_by_default(): void
    {
        [$c, $u, $customer] = $this->contextWithCustomer();
        $this->scheduleVisit($c, $u, $customer, 'pendiente');

        $content = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.today'))->getContent();

        // Ambos paneles existen pero ocultos hasta elegir (clase hidden).
        $this->assertStringContainsString('data-panel="promesa_pago" class="mt-3 hidden', $content);
        $this->assertStringContainsString('data-panel="reprogramada" class="mt-3 hidden', $content);
    }

    public function test_promise_form_uses_decimal_keyboard_and_short_label(): void
    {
        [$c, $u, $customer] = $this->contextWithCustomer();
        $this->scheduleVisit($c, $u, $customer, 'pendiente');

        $content = $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->get(route('cobros.visits.today'))->getContent();

        $this->assertStringContainsString('¿Cuánto prometió? (opcional)', $content);
        $this->assertStringNotContainsString('solo de referencia', $content);
        $this->assertMatchesRegularExpression('/name="promise_amount"[^>]*inputmode="decimal"|inputmode="decimal"[^>]*name="promise_amount"/', $content);
        $this->assertMatchesRegularExpression('/name="promise_date"[^>]*type="date"|type="date"[^>]*name="promise_date"/', $content);
    }
}
