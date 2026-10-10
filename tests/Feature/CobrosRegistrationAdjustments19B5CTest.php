<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CobrosModalityCatalog;
use App\Services\Cobros\LaborInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosRegistrationAdjustments19B5CTest extends TestCase
{
    use RefreshDatabase;

    private function completeFirstStep(string $modality = 'prestamista'): void
    {
        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR',
            'modality' => $modality,
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));
    }

    public function test_welcome_shows_country_flags_after_name_and_four_accesses(): void
    {
        $content = $this->get(route('cobros.landing'))->assertOk()->getContent();

        $this->assertStringContainsString('Iniciar sesión', $content);
        $this->assertStringContainsString('Crear cuenta', $content);
        $this->assertStringContainsString('Probar DEMO', $content);
        $this->assertStringContainsString('Ver videos explicativos', $content);

        // La bandera del país (🇨🇷 = U+1F1E8 U+1F1F7) se sirve desde
        // configuración y aparece DESPUÉS del nombre en el HTML de la landing.
        $flag = mb_chr(0x1F1E8, 'UTF-8').mb_chr(0x1F1F7, 'UTF-8');
        $flagPos = mb_strpos($content, $flag);
        $namePos = mb_strpos($content, 'Costa Rica');
        $this->assertNotFalse($namePos);
        $this->assertNotFalse($flagPos, 'La bandera de Costa Rica debe aparecer en la landing.');
        $this->assertGreaterThan($namePos, $flagPos, 'La bandera debe ir a la derecha del nombre del país.');
    }

    public function test_welcome_has_gold_accent_title_and_country_cta_component(): void
    {
        $content = $this->get(route('cobros.landing'))->assertOk()->getContent();

        $this->assertStringContainsString('Bienvenidos a <span class="text-primary">MVS CobrOS</span>', $content);
        $this->assertStringContainsString('¿Tu país aún no está disponible? ¡Queremos llegar hasta vos!', $content);
        $this->assertStringContainsString('mailto:ventas@mvscommerce.com', $content);
        $this->assertStringContainsString(rawurlencode('Solicitud de MVS CobrOS para mi país'), $content);
    }

    public function test_step_one_hides_modality_options_when_already_selected(): void
    {
        $this->get(route('cobros.register', ['modality' => 'prestamista']))
            ->assertRedirect(route('cobros.register.step', ['paso' => 1]));

        $step = $this->get(route('cobros.register.step', ['paso' => 1]));
        $step->assertOk()
            ->assertSee('País')
            ->assertDontSee('Modalidad de operación')
            ->assertDontSee('type="radio" name="modality"', false)
            ->assertSee('name="modality"', false);
    }

    public function test_step_one_shows_modality_options_when_none_selected(): void
    {
        $step = $this->get(route('cobros.register.step', ['paso' => 1]));
        $step->assertOk()
            ->assertSee('País y modalidad')
            ->assertSee('Modalidad de operación')
            ->assertSee('value="prestamista"', false)
            ->assertSee('value="cobrador_independiente"', false)
            ->assertSee('value="cobrador_vinculado"', false);
    }

    public function test_country_step_accepts_persisted_modality_without_resending_options(): void
    {
        $this->get(route('cobros.register', ['modality' => 'cobrador_independiente']))
            ->assertRedirect(route('cobros.register.step', ['paso' => 1]));

        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR',
            'modality' => 'cobrador_independiente',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->assertSame('cobrador_independiente', session('cobros.registration')['modality']);
    }

    public function test_register_steps_hide_login_link_but_welcome_keeps_it(): void
    {
        $this->get(route('cobros.register.step', ['paso' => 1]))->assertOk()->assertDontSee('Iniciar sesión');

        $this->completeFirstStep();
        $this->get(route('cobros.register.step', ['paso' => 2]))->assertOk()->assertDontSee('Iniciar sesión');

        $this->post(route('cobros.register.personal'), [
            'name' => 'Oculta Login',
            'email' => 'ocultalogin5c@test.com',
            'phone' => '8888-5555',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));
        $this->get(route('cobros.register.step', ['paso' => 3]))->assertOk()->assertDontSee('Iniciar sesión');

        $this->post(route('cobros.register.routes.save'), ['name' => 'Ruta Login'])->assertRedirect(route('cobros.register.step', ['paso' => 3]));
        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])->assertRedirect(route('cobros.register.step', ['paso' => 4]));
        $this->get(route('cobros.register.step', ['paso' => 4]))->assertOk()->assertDontSee('Iniciar sesión');

        $this->get(route('cobros.landing'))->assertOk()->assertSee('Iniciar sesión');
    }

    public function test_phone_is_required_on_personal_data_step(): void
    {
        $this->completeFirstStep();

        $this->post(route('cobros.register.personal'), [
            'name' => 'Sin Teléfono',
            'email' => 'sintelefono@test.com',
        ])->assertSessionHasErrors('phone');

        $this->post(route('cobros.register.personal'), [
            'name' => 'Con Teléfono',
            'email' => 'contelefono@test.com',
            'phone' => '8888-8888',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->assertSame('8888-8888', session('cobros.registration')['personal']['phone']);
    }

    public function test_phone_rejects_invalid_format(): void
    {
        $this->completeFirstStep();

        $this->post(route('cobros.register.personal'), [
            'name' => 'Teléfono Malo',
            'email' => 'telmalo@test.com',
            'phone' => 'abc',
        ])->assertSessionHasErrors('phone');
    }

    public function test_review_shows_lender_name_for_linked_collector_with_valid_invitation(): void
    {
        $lender = User::factory()->create(['is_active' => true, 'name' => 'Prestamista Ana Prueba', 'email' => 'ana5c@invitacion.test']);
        $company = Company::create([
            'trade_name' => 'Empresa Prueba 5C',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $lender->id,
        ]);

        $issued = app(LaborInvitationService::class)->issue($company, $lender, 'vinculado5c@invitacion.test');

        $this->get(route('cobros.register.invitation', ['token' => $issued['plain']]))
            ->assertRedirect(route('cobros.register.step', ['paso' => 1]));

        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR',
            'modality' => CobrosModalityCatalog::COBRADOR_VINCULADO,
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->post(route('cobros.register.personal'), [
            'name' => 'Cobrador Vinculado',
            'email' => 'vinculado5c@test.com',
            'phone' => '8888-9999',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.routes.save'), [
            'name' => 'Ruta Vinculada',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $this->get(route('cobros.register.step', ['paso' => 4]))
            ->assertOk()
            ->assertSee('Pertenece al prestamista: Prestamista Ana Prueba');
    }

    public function test_review_shows_pending_link_state_for_linked_collector_without_invitation(): void
    {
        // Llegada directa con modalidad vinculada SIN invitación válida.
        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR',
            'modality' => CobrosModalityCatalog::COBRADOR_VINCULADO,
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->post(route('cobros.register.personal'), [
            'name' => 'Sin Vinculo',
            'email' => 'sinvinculo5c@test.com',
            'phone' => '8888-7777',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.routes.save'), [
            'name' => 'Ruta Sin Vinculo',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $page = $this->get(route('cobros.register.step', ['paso' => 4]));
        $page->assertOk()
            ->assertSee('Invitación pendiente')
            ->assertDontSee('Pertenece al prestamista:');
    }

    public function test_review_does_not_show_lender_field_for_other_modalities(): void
    {
        $this->completeFirstStep(CobrosModalityCatalog::PRESTAMISTA);

        $this->post(route('cobros.register.personal'), [
            'name' => 'Prestamista Solo',
            'email' => 'prestasolo5c@test.com',
            'phone' => '8888-6666',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.routes.save'), [
            'name' => 'Ruta Prestamista',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $this->get(route('cobros.register.step', ['paso' => 4]))
            ->assertOk()
            ->assertDontSee('Pertenece al prestamista')
            ->assertDontSee('Vínculo laboral');
    }
}
