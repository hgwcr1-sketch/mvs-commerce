<?php

namespace Tests\Feature;

use App\Http\Controllers\Cobros\PublicLandingController;
use App\Http\Controllers\Cobros\PublicRegistrationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRE-19B-5B — bienvenida simplificada: navegación de los cuatro accesos,
 * selección de exactamente tres modalidades, redirección de sesión activa y
 * preservación independiente de referidos e invitaciones laborales.
 */
class CobrosPublicWelcome19B5ATest extends TestCase
{
    use RefreshDatabase;

    private const LANDING = '/bienvenida-cobros';

    public function test_welcome_renders_simplified_headline_and_approved_hero(): void
    {
        $response = $this->get(self::LANDING);

        $response->assertOk()
            ->assertSee('Bienvenidos a', false)
            ->assertSee('<span class="text-primary">MVS CobrOS</span>', false)
            ->assertSee('Tu gestión de cobros, más sencilla')
            ->assertSee(asset('images/cobros/welcome/02_hero_bienvenida_laptop.webp'), false)
            ->assertSee(asset('images/logo-mvs-cobros.png'), false);
    }

    public function test_four_independent_accesses_render_in_order(): void
    {
        $content = $this->get(self::LANDING)->assertOk()->getContent();

        $login = strpos($content, 'Iniciar sesión');
        $create = strpos($content, 'Crear cuenta');
        $demo = strpos($content, 'Probar DEMO');
        $videos = strpos($content, 'Ver videos explicativos');

        $this->assertNotFalse($login);
        $this->assertNotFalse($create);
        $this->assertNotFalse($demo);
        $this->assertNotFalse($videos);
        $this->assertTrue($login < $create && $create < $demo && $demo < $videos, 'Los cuatro accesos deben aparecer en el orden aprobado.');
    }

    public function test_modality_selection_offers_exactly_three_paths_to_existing_registration(): void
    {
        $content = $this->get(self::LANDING)->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('cobros.register', ['modality' => 'prestamista']).'"', $content);
        $this->assertStringContainsString('href="'.route('cobros.register', ['modality' => 'cobrador_independiente']).'"', $content);
        $this->assertStringContainsString('href="'.route('cobros.register', ['modality' => 'cobrador_vinculado']).'"', $content);
        $this->assertStringContainsString('Trabajo para un prestamista', $content);
        $this->assertStringNotContainsString('prestamista_cobrador', $content, 'Solo las tres modalidades aprobadas.');
        $this->assertStringNotContainsString('multiprestamista', $content, 'Solo las tres modalidades aprobadas.');
    }

    public function test_creating_account_preseeds_modality_in_registration_session(): void
    {
        $response = $this->get('/registro-cobros?modality=cobrador_independiente');

        $response->assertRedirect();
        $this->assertSame(
            'cobrador_independiente',
            session(PublicRegistrationController::SESSION_KEY)['modality'] ?? null,
            'La modalidad elegida en la bienvenida debe presembrarse en el registro existente.'
        );
    }

    public function test_existing_session_modality_is_not_overridden_by_welcome_link(): void
    {
        $this->withSession([
            PublicRegistrationController::SESSION_KEY => ['modality' => 'cobrador_vinculado'],
        ])->get('/registro-cobros?modality=prestamista')->assertRedirect();

        $this->assertSame(
            'cobrador_vinculado',
            session(PublicRegistrationController::SESSION_KEY)['modality'],
            'Una invitación/modalidad previa manda sobre el enlace de la bienvenida.'
        );
    }

    public function test_authenticated_session_is_redirected_to_cobros_panel_from_login(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $response = (new \App\Http\Controllers\Cobros\LoginController())->create();

        $this->assertSame(route('cobros.index'), $response->getTargetUrl());
    }

    public function test_guest_gets_login_view_from_login_entry(): void
    {
        $response = (new \App\Http\Controllers\Cobros\LoginController())->create();

        $this->assertSame('cobros.auth.login', $response->getName());
    }

    public function test_referral_and_labor_invitation_survive_independently_on_welcome(): void
    {
        $response = $this->withSession([
            PublicLandingController::REFERRAL_SESSION_KEY => ['code' => 'REFkeep_99', 'captured_at' => now()->toIso8601String()],
            PublicRegistrationController::SESSION_KEY => ['modality' => 'cobrador_vinculado', 'labor_invitation' => ['id' => 1]],
        ])->get(self::LANDING.'?ref=MVSabc_12345');

        $response->assertOk()->assertDontSee('MVSabc_12345', false);

        $this->assertSame(
            'MVSabc_12345',
            session(PublicLandingController::REFERRAL_SESSION_KEY)['code'] ?? null,
            'El último ?ref= válido de la sesión gana; nunca se expone en el HTML.'
        );
        $this->assertSame(
            1,
            session(PublicRegistrationController::SESSION_KEY)['labor_invitation']['id'] ?? null,
            'La invitación laboral vive en su propia clave, independiente del ?ref=.'
        );
    }

    public function test_demo_and_videos_navigate_to_real_pages_and_countries_dynamic(): void
    {
        $content = $this->get(self::LANDING)->assertOk()->getContent();

        // PRE-19B-5D: DEMO y Videos tienen ruta propia; la bienvenida no
        // conserva CTA deshabilitados y los países siguen dinámicos.
        $this->assertSame(0, substr_count($content, 'aria-disabled="true"'), 'La bienvenida no debe quedar con CTA inactivos.');
        $this->assertStringContainsString('href="'.route('cobros.demo.entry').'"', $content);
        $this->assertStringContainsString('href="'.route('cobros.videos').'"', $content);
        $this->assertStringContainsString('Costa Rica', $content);
        $this->assertStringContainsString('Colombia', $content);
    }
}
