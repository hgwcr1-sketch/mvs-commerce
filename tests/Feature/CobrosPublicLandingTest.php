<?php

namespace Tests\Feature;

use Tests\TestCase;

class CobrosPublicLandingTest extends TestCase
{
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
        $root = $this->get('/');
        $root->assertStatus(302);
        $this->assertStringContainsString('/login', (string) $root->headers->get('Location'));
        $this->get('/dashboard')->assertStatus(302);
        $this->get(self::LANDING)->assertOk();
        $this->assertGuest();
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
