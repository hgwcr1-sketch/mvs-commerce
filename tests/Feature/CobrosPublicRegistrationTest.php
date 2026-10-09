<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Cobros\CobrosModalityCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosPublicRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function completeFirstStep(string $country = 'CR', string $modality = 'prestamista'): void
    {
        $this->post(route('cobros.register.country'), [
            'country_iso' => $country,
            'modality' => $modality,
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));
    }

    public function test_registration_start_is_public_and_shows_countries_modalities_and_progress(): void
    {
        $response = $this->get(route('cobros.register'));

        $response->assertRedirect(route('cobros.register.step', ['paso' => 1]));
        $this->assertGuest();

        $step = $this->get(route('cobros.register.step', ['paso' => 1]));
        $step->assertOk()->assertViewIs('cobros.register.step1')
            ->assertSee('Costa Rica')
            ->assertSee('Colombia')
            ->assertSee('País y modalidad')
            ->assertSee('Datos personales')
            ->assertSee('Revisión')
            ->assertSee('name="_token"', false)
            ->assertSee('sm:grid-cols-5', false)
            ->assertSee('Rutas')
            ->assertSee('content="#D4AF37"', false);

        // BLOQUE 16R-1: la modalidad multiprestamista queda oculta del
        // onboarding público mientras la bandera del Panel Maestro esté
        // desactivada (valor por defecto); el catálogo total sigue en 5.
        $visible = collect(CobrosModalityCatalog::all())
            ->except(CobrosModalityCatalog::COBRADOR_MULTIPRESTAMISTA);
        foreach ($visible as $definition) {
            $step->assertSee($definition['label']);
        }
        $step->assertDontSee(CobrosModalityCatalog::label(CobrosModalityCatalog::COBRADOR_MULTIPRESTAMISTA));
        $this->assertSame(5, count(CobrosModalityCatalog::all()));
        $this->assertSame(4, count($visible));
        $this->assertGuest();
    }

    public function test_first_step_validates_country_and_modality(): void
    {
        $this->post(route('cobros.register.country'), [
            'country_iso' => 'NI',
            'modality' => 'prestamista',
        ])->assertSessionHasErrors(['country_iso']);

        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR',
            'modality' => 'no_existe',
        ])->assertSessionHasErrors(['modality']);

        $this->assertNull(session('cobros.registration'));

        $this->completeFirstStep('CO', 'cobrador_independiente');

        $registration = session('cobros.registration');
        $this->assertSame('CO', $registration['country_iso']);
        $this->assertSame('cobrador_independiente', $registration['modality']);
        $this->assertArrayHasKey('updated_at', $registration);
    }

    public function test_steps_cannot_be_skipped_or_guessed(): void
    {
        $this->get(route('cobros.register.step', ['paso' => 2]))
            ->assertRedirect(route('cobros.register.step', ['paso' => 1]));

        $this->post(route('cobros.register.personal'), [
            'name' => 'Persona Test',
            'email' => 'persona@test.com',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 1]));
        $this->assertNull(session('cobros.registration'));

        $this->get(route('cobros.register.step', ['paso' => 9]))->assertStatus(422);
        $this->get(route('cobros.register.step', ['paso' => 0]))->assertStatus(422);
    }

    public function test_personal_data_saves_and_opens_review_without_creating_accounts(): void
    {
        $this->completeFirstStep();

        $this->post(route('cobros.register.personal'), [
            'name' => 'María Fernández',
            'email' => 'maria.registro@test.com',
            'phone' => '8888-1111',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $personal = session('cobros.registration')['personal'];
        $this->assertSame('María Fernández', $personal['name']);
        $this->assertSame('maria.registro@test.com', $personal['email']);

        $this->get(route('cobros.register.step', ['paso' => 4]))
            ->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $review = $this->get(route('cobros.register.step', ['paso' => 4]));
        $review->assertOk()->assertViewIs('cobros.register.step4')
            ->assertSee('María Fernández')
            ->assertSee('maria.registro@test.com')
            ->assertSee('8888-1111')
            ->assertSee('Costa Rica')
            ->assertSee('Prestamista')
            ->assertSee('Registro manual')
            ->assertSee('no se genera ningún acceso')
            ->assertSee('aria-disabled="true"', false);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertGuest();
    }

    public function test_duplicate_email_is_rejected_by_existing_uniqueness_rule(): void
    {
        User::create([
            'name' => 'Usuario Existente',
            'email' => 'ocupado@test.com',
            'password' => bcrypt('Secret123*'),
        ]);

        $this->completeFirstStep();

        $this->post(route('cobros.register.personal'), [
            'name' => 'Otro Usuario',
            'email' => 'ocupado@test.com',
        ])->assertSessionHasErrors('email');

        $this->assertArrayNotHasKey('personal', session('cobros.registration') ?? []);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_progress_can_go_back_correct_and_resume_within_session(): void
    {
        $this->completeFirstStep('CR', 'prestamista');

        $back = $this->get(route('cobros.register.step', ['paso' => 1]));
        $back->assertOk()->assertSee('value="CR"', false)->assertSee('checked', false);

        $this->get(route('cobros.register.step', ['paso' => 3]))
            ->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CO',
            'modality' => 'cobrador_independiente',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->post(route('cobros.register.personal'), [
            'name' => 'Corrected Name',
            'email' => 'corrected@test.com',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->get(route('cobros.register.step', ['paso' => 3]))
            ->assertOk()
            ->assertSee('Colombia');

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'none'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $this->get(route('cobros.register.step', ['paso' => 4]))
            ->assertOk()
            ->assertSee('Colombia')
            ->assertSee('Corrected Name');
        $this->assertSame('CO', session('cobros.registration')['country_iso']);
    }

    public function test_referral_captured_on_landing_is_preserved_without_exposure(): void
    {
        $this->get('/bienvenida-cobros?ref=REF-abc_12345')->assertOk();

        $this->completeFirstStep();
        $this->post(route('cobros.register.personal'), [
            'name' => 'Con Referencia',
            'email' => 'referida@test.com',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $referral = session('cobros.referral');
        $this->assertSame('REF-abc_12345', $referral['code']);

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'none'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $review = $this->get(route('cobros.register.step', ['paso' => 4]));
        $review->assertOk()
            ->assertSee('Capturada automáticamente')
            ->assertDontSee('REF-abc_12345');

        $this->get('/bienvenida-cobros')->assertOk();
        $this->assertSame('REF-abc_12345', session('cobros.referral')['code']);
    }

    public function test_registration_is_isolated_from_commerce_and_creates_no_access(): void
    {
        $this->get('/')->assertStatus(302);
        $this->get('/dashboard')->assertStatus(302);

        $this->completeFirstStep();
        $this->post(route('cobros.register.personal'), [
            'name' => 'Sin Acceso Todavía',
            'email' => 'sinacceso@test.com',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));
        $this->get(route('cobros.register.step', ['paso' => 4]))->assertOk();
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('company_licenses', 0);
        $this->assertDatabaseCount('cobros_routes', 0);
        $this->assertDatabaseCount('customers', 0);

        $this->get(route('cobros.login'))->assertOk();
        $this->get('/bienvenida-cobros')->assertOk();
    }

    /**
     * BLOQUE 16R-1 — un cobrador = una ruta y un prestamista; la modalidad
     * multiprestamista queda oculta y bloqueada por el servidor; el segundo
     * prestamista se rechaza con la misma regla que la segunda ruta.
     */
    public function test_one_collector_one_route_and_multi_lender_blocked_server_side(): void
    {
        // 1) Multiprestamista: oculta en la interfaz y bloqueada por servidor.
        $this->get(route('cobros.register.step', ['paso' => 1]))
            ->assertOk()
            ->assertDontSee('Cobrador multiprestamista');

        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR',
            'modality' => 'cobrador_multiprestamista',
        ])->assertSessionHasErrors('modality');
        $this->assertNull(session('cobros.registration')['modality'] ?? null);

        // 2) Una ruta permitida; la segunda se rechaza.
        $this->completeFirstStep('CR', 'cobrador_independiente');
        $this->post(route('cobros.register.personal'), [
            'name' => 'Cobrador Único',
            'email' => 'unico.ruta@test.com',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.routes.save'), ['name' => 'Ruta Norte'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 3]));
        $this->assertCount(1, session('cobros.registration')['routes']);

        $this->post(route('cobros.register.routes.save'), ['name' => 'Ruta Sur'])
            ->assertSessionHasErrors('name');
        $this->assertCount(1, session('cobros.registration')['routes']);

        // 3) La vista del paso 3 oculta el formulario de nueva ruta.
        $this->get(route('cobros.register.step', ['paso' => 3]))
            ->assertOk()
            ->assertSee('Ruta Norte')
            ->assertDontSee('name="name"', false);

        // 4) Sin activación ni entidades operativas.
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('cobros_routes', 0);
        $this->assertGuest();
    }
}
