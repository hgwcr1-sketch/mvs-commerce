<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosPublicLandingTest extends TestCase
{
    use RefreshDatabase;

    private const LANDING = '/bienvenida-cobros';

    public function test_landing_is_publicly_accessible_for_anonymous_visitors(): void
    {
        $response = $this->get(self::LANDING);

        $response->assertOk()->assertViewIs('cobros.public-landing');
        $this->assertGuest();
    }

    public function test_landing_shows_ctas_with_registration_login_and_coming_soon(): void
    {
        $response = $this->get(self::LANDING);
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('Crear cuenta')
            ->assertSee('Probar DEMO')
            ->assertSee('Ver videos')
            ->assertSee('Iniciar sesión')
            ->assertSee('href="'.route('cobros.register', ['modality' => 'prestamista']).'"', false)
            ->assertSee('href="'.route('cobros.login').'"', false)
            ->assertSee('href="'.route('cobros.demo.entry').'"', false)
            ->assertSee('href="'.route('cobros.videos').'"', false)
            ->assertDontSee('href="'.route('login').'"', false);

        // PRE-19B-5D: DEMO y Videos ya tienen ruta propia; la landing no
        // conserva CTA deshabilitados ni «Próximamente».
        $this->assertSame(0, substr_count($content, 'Próximamente'), 'La landing no debe quedar con CTA próximamente.');
        $this->assertSame(0, substr_count($content, 'aria-disabled="true"'), 'La landing no debe quedar con CTA inactivos.');
    }

    public function test_landing_lists_only_available_countries_with_enablement_note(): void
    {
        $response = $this->get(self::LANDING);

        $response->assertOk()
            ->assertSee('Costa Rica')
            ->assertSee('Colombia')
            ->assertDontSee('>Disponible<', false)
            ->assertSee('podrán solicitar su habilitación')
            ->assertDontSee('Nicaragua')
            ->assertDontSee('Guatemala')
            ->assertDontSee('México');
    }

    public function test_valid_ref_is_kept_in_session_without_exposure_or_benefits(): void
    {
        $response = $this->get(self::LANDING.'?ref=REF-abc_12345');

        $response->assertOk()->assertDontSee('REF-abc_12345');

        $stored = session('cobros.referral');
        $this->assertIsArray($stored);
        $this->assertSame('REF-abc_12345', $stored['code']);
        $this->assertArrayHasKey('captured_at', $stored);
    }

    public function test_malformed_or_missing_ref_is_ignored(): void
    {
        $invalid = ['ab', 'x', str_repeat('a', 65), 'ref con espacios', '<script>alert(1)</script>'];

        foreach ($invalid as $value) {
            $this->get(self::LANDING.'?ref='.urlencode($value))->assertOk();
            $this->assertNull(session('cobros.referral'), "La referencia inválida no debe conservarse: {$value}");
        }

        $this->get(self::LANDING)->assertOk();
        $this->assertNull(session('cobros.referral'));
    }

    public function test_landing_does_not_disturb_commerce_or_authentication_routes(): void
    {
        // WEB-01: la raíz / ahora sirve la landing para anónimos.
        $root = $this->get('/');
        $root->assertOk()->assertViewIs('cobros.public-landing');
        $this->assertGuest();
        $this->get('/dashboard')->assertStatus(302);
        $this->get(self::LANDING)->assertOk();
        $this->assertGuest();
    }

    public function test_authenticated_users_are_redirected_from_root_to_their_panel(): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => true]);
        $company = \App\Models\Company::create([
            'trade_name' => 'Raiz '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $role = \App\Models\Role::create(['company_id' => $company->id, 'name' => 'Rol raiz', 'is_active' => true]);
        $permission = \App\Models\Permission::firstOrCreate(
            ['name' => 'cobros.acceder'],
            ['label' => 'cobros.acceder', 'module' => 'CobrOS', 'is_active' => true]
        );
        $role->permissions()->attach($permission->id);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        $this->actingAs($user)->withSession(['active_company_id' => $company->id])
            ->get('/')
            ->assertRedirect(route('cobros.index'));
    }

    public function test_landing_is_mobile_first_with_cobros_brand_identity(): void
    {
        $response = $this->get(self::LANDING);

        $response->assertOk()
            ->assertSee('name="viewport"', false)
            ->assertSee('content="#D4AF37"', false)
            ->assertSee('lg:grid-cols-4', false)
            ->assertSee('min-h-11', false)
            ->assertSee('logo-mvs-cobros.png')
            ->assertSee('MVS CobrOS')
            ->assertDontSee('MVS Commerce');
    }
}
