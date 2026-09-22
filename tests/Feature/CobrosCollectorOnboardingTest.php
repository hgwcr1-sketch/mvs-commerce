<?php

namespace Tests\Feature;

use App\Models\{Branch, Company, Permission, Role, User};
use App\Services\{CompanyCashSettingsProvisioner, Cobros\CollectorManagementService, Cobros\CollectorPasswordService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, Hash};
use Tests\TestCase;

class CobrosCollectorOnboardingTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | PASSWORD GENERATOR
     | --------------------------------------------------------------------- */

    public function test_password_format_is_six_chars_one_upper_one_lower_four_digits(): void
    {
        $service = app(CollectorPasswordService::class);

        for ($i = 0; $i < 100; $i++) {
            $pw = $service->generate();
            $this->assertSame(6, strlen($pw), "Password '$pw' should be 6 chars");
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{6}$/', $pw);
            $this->assertSame(1, preg_match_all('/[A-Z]/', $pw), "Password '$pw' should have exactly 1 uppercase");
            $this->assertSame(1, preg_match_all('/[a-z]/', $pw), "Password '$pw' should have exactly 1 lowercase");
            $this->assertSame(4, preg_match_all('/[0-9]/', $pw), "Password '$pw' should have exactly 4 digits");
        }
    }

    public function test_password_validate_format_rejects_invalid(): void
    {
        $service = app(CollectorPasswordService::class);

        $this->assertFalse($service->validateFormat('ABmora4821')); // 10 chars
        $this->assertFalse($service->validateFormat('Aa482'));      // 5 chars
        $this->assertFalse($service->validateFormat('AA4821'));      // no lowercase
        $this->assertFalse($service->validateFormat('aa4821'));      // no uppercase
        $this->assertFalse($service->validateFormat('Aabc12'));      // 3 digits only
        $this->assertFalse($service->validateFormat('Aa482!'));      // special char
        $this->assertFalse($service->validateFormat(''));            // empty
    }

    public function test_password_validate_format_accepts_valid(): void
    {
        $service = app(CollectorPasswordService::class);

        $this->assertTrue($service->validateFormat('Aa4821'));
        $this->assertTrue($service->validateFormat('Bx0099'));
        $this->assertTrue($service->validateFormat('Zq1234'));
    }

    /* ---------------------------------------------------------------------
     | CREATE COLLECTOR: must_change_password + hash
     | --------------------------------------------------------------------- */

    public function test_create_collector_sets_must_change_password_and_hashes(): void
    {
        [$company, $admin] = $this->setupCompany();
        $service = app(CollectorManagementService::class);

        $result = $service->createCollector($company, [
            'name' => 'Nuevo Cobrador',
            'email' => 'nuevo@test.com',
        ], $admin, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('plain_password', $result);

        $user = $result['user'];
        $plain = $result['plain_password'];

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->must_change_password);
        $this->assertNotSame($plain, $user->password);
        $this->assertTrue(Hash::check($plain, $user->password));
        $this->assertSame(6, strlen($plain));
    }

    public function test_create_existing_user_sets_must_change_password(): void
    {
        [$company, $admin] = $this->setupCompany();
        $service = app(CollectorManagementService::class);

        $existing = User::factory()->create(['email' => 'exist@t.com', 'is_active' => true]);

        $result = $service->createCollector($company, [
            'name' => $existing->name,
            'email' => 'exist@t.com',
        ], $admin, true);

        $this->assertTrue($result['user']->must_change_password);
        $this->assertTrue(Hash::check($result['plain_password'], $result['user']->password));
    }

    /* ---------------------------------------------------------------------
     | REGENERATE ACCESS
     | --------------------------------------------------------------------- */

    public function test_regenerate_access_sets_new_must_change_password(): void
    {
        [$company, $admin, $collector] = $this->cupoContextWithCollector();
        $service = app(CollectorManagementService::class);

        $oldHash = $collector->password;
        $newPlain = $service->regenerateAccess($collector, $company);

        $this->assertSame(6, strlen($newPlain));
        $this->assertTrue(Hash::check($newPlain, $collector->fresh()->password));
        $this->assertTrue($collector->fresh()->must_change_password);
        $this->assertNotSame($oldHash, $collector->fresh()->password);
    }

    /* ---------------------------------------------------------------------
     | FIRST LOGIN FORCES PASSWORD CHANGE
     | --------------------------------------------------------------------- */

    public function test_cobros_login_redirects_to_force_change_when_must_change_password(): void
    {
        [$company, $admin, $collector] = $this->cupoContextWithCollector();

        $this->app['auth']->forgetGuards();

        $this->post(route('cobros.login.store'), [
            'email' => $collector->email,
            'password' => 'password123',
        ])->assertRedirect(route('cobros.password.force'));
    }

    public function test_cobros_login_does_not_redirect_when_password_not_forced(): void
    {
        [$company, $admin, $collector] = $this->cupoContextWithCollector();
        $collector->update(['must_change_password' => false]);

        $this->app['auth']->forgetGuards();

        $this->post(route('cobros.login.store'), [
            'email' => $collector->email,
            'password' => 'password123',
        ])->assertRedirect(route('cobros.index'));
    }

    public function test_force_change_password_view_renders(): void
    {
        [$company, $admin, $collector] = $this->cupoContextWithCollector();
        $collector->update(['must_change_password' => true]);

        $this->actingAs($collector)->get(route('cobros.password.force'))
            ->assertOk()
            ->assertSee('Crea tu nueva contraseña')
            ->assertSee('Nueva contraseña')
            ->assertSee('Confirmar contraseña');
    }

    public function test_force_change_password_updates_and_clears_flag(): void
    {
        [$company, $admin, $collector] = $this->cupoContextWithCollector();
        $collector->update(['must_change_password' => true]);

        $this->actingAs($collector)->post(route('cobros.password.force.store'), [
            'password' => 'NuevaClave1',
            'password_confirmation' => 'NuevaClave1',
        ])->assertRedirect(route('cobros.index'));

        $collector->refresh();
        $this->assertFalse($collector->must_change_password);
        $this->assertTrue(Hash::check('NuevaClave1', $collector->password));
        $this->assertTrue(Hash::check('password123', $collector->password) === false);
    }

    public function test_temp_password_stops_working_after_change(): void
    {
        [$company, $admin, $collector] = $this->cupoContextWithCollector();
        $collector->update(['must_change_password' => true]);

        // Change password
        $this->actingAs($collector)->post(route('cobros.password.force.store'), [
            'password' => 'NuevaClave1',
            'password_confirmation' => 'NuevaClave1',
        ]);

        // Try to login with old temp password — should fail
        Auth::logout();
        $this->post(route('cobros.login.store'), [
            'email' => $collector->email,
            'password' => 'password123',
        ])->assertRedirect(route('cobros.login'));

        // Login with new password should work
        $this->post(route('cobros.login.store'), [
            'email' => $collector->email,
            'password' => 'NuevaClave1',
        ]);

        $this->assertAuthenticated();
    }

    /* ---------------------------------------------------------------------
     | FULL FLOW: temp password → login → force change → access
     | --------------------------------------------------------------------- */

    public function test_full_flow_temp_login_force_change_access(): void
    {
        [$company, $admin] = $this->cupoContext();
        $service = app(CollectorManagementService::class);

        // Step 1: Create collector — generates temp password
        $result = $service->createCollector($company, [
            'name' => 'Flow Test',
            'email' => 'flow@test.com',
        ], $admin);
        $tempPassword = $result['plain_password'];
        $collector = $result['user'];

        $this->assertSame(6, strlen($tempPassword));
        $this->assertTrue($collector->must_change_password);

        // Step 2: Login with temp password → forced to change
        $this->app['auth']->forgetGuards();
        $this->post(route('cobros.login.store'), [
            'email' => $collector->email,
            'password' => $tempPassword,
        ])->assertRedirect(route('cobros.password.force'));

        // Step 3: Force change view renders
        $this->actingAs($collector)->get(route('cobros.password.force'))
            ->assertOk()
            ->assertSee('Crea tu nueva contraseña');

        // Step 4: Submit new password
        $this->actingAs($collector)->post(route('cobros.password.force.store'), [
            'password' => 'NuevaClave1',
            'password_confirmation' => 'NuevaClave1',
        ])->assertRedirect(route('cobros.index'));

        // Step 5: Verify flag cleared and new password works
        $collector->refresh();
        $this->assertFalse($collector->must_change_password);
        $this->assertTrue(Hash::check('NuevaClave1', $collector->password));

        // Step 6: Temp password no longer works
        Auth::logout();
        $this->post(route('cobros.login.store'), [
            'email' => $collector->email,
            'password' => $tempPassword,
        ])->assertRedirect(route('cobros.login'));
        $this->assertGuest();

        // Step 7: New password grants access
        $this->post(route('cobros.login.store'), [
            'email' => $collector->email,
            'password' => 'NuevaClave1',
        ]);
        $this->assertAuthenticated();
    }

    /* ---------------------------------------------------------------------
     | PLATFORM ADMIN: individual mode with temp password
     | --------------------------------------------------------------------- */

    public function test_platform_admin_create_collector_returns_temp_password(): void
    {
        [$company, $admin] = $this->individualContext();

        $response = $this->actingAs($admin)
            ->post(route('platform.collectors.store', $company), [
                'name' => 'Cobrador Individual',
                'email' => 'ci@test.com',
                'phone' => '88881234',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('collector_plain_password');
        $response->assertSessionHas('collector_email', 'ci@test.com');

        $collector = User::where('email', 'ci@test.com')->first();
        $this->assertNotNull($collector);
        $this->assertTrue($collector->must_change_password);
        $this->assertTrue($collector->hasPermission('cobros.cobrar', $company));
    }

    public function test_platform_admin_create_collector_no_password_field_required(): void
    {
        [$company, $admin] = $this->individualContext();

        $this->actingAs($admin)
            ->post(route('platform.collectors.store', $company), [
                'name' => 'Sin Password',
                'email' => 'sp@test.com',
            ])->assertRedirect();

        $collector = User::where('email', 'sp@test.com')->first();
        $this->assertNotNull($collector);
        $this->assertTrue($collector->must_change_password);
    }

    public function test_platform_admin_regenerate_access(): void
    {
        [$company, $admin, $collector] = $this->individualContextWithCollector();

        $response = $this->actingAs($admin)
            ->post(route('platform.collectors.regenerate', [$company, $collector]));

        $response->assertRedirect();
        $response->assertSessionHas('collector_plain_password');
        $this->assertTrue($collector->fresh()->must_change_password);
    }

    /* ---------------------------------------------------------------------
     | OWNER: cupo mode with temp password
     | --------------------------------------------------------------------- */

    public function test_owner_create_collector_returns_temp_password(): void
    {
        [$company, $admin, $owner] = $this->cupoContext();

        $response = $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.collectors.store'), [
                'name' => 'Juan Cobrador',
                'email' => 'juan@test.com',
                'phone' => '88889999',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('collector_plain_password');
        $response->assertSessionHas('collector_email', 'juan@test.com');

        $collector = User::where('email', 'juan@test.com')->first();
        $this->assertNotNull($collector);
        $this->assertTrue($collector->must_change_password);
        $this->assertTrue($collector->hasPermission('cobros.cobrar', $company));
    }

    public function test_owner_regenerate_access(): void
    {
        [$company, $admin, $collector, $owner] = $this->cupoContextWithCollector();

        $response = $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.collectors.regenerate', $collector));

        $response->assertRedirect();
        $response->assertSessionHas('collector_plain_password');
        $this->assertTrue($collector->fresh()->must_change_password);
    }

    /* ---------------------------------------------------------------------
     | WHATSAPP: phone normalization + message
     | --------------------------------------------------------------------- */

    public function test_platform_show_renders_whatsapp_link_with_normalized_phone(): void
    {
        [$company, $admin] = $this->individualContext();

        $this->actingAs($admin)
            ->post(route('platform.collectors.store', $company), [
                'name' => 'WhatsApp Test',
                'email' => 'wa@test.com',
                'phone' => '88881234',
            ]);

        $response = $this->actingAs($admin)
            ->get(route('platform.companies.show', $company));

        $response->assertOk();
        $response->assertSee('wa.me/50688881234');
        $response->assertSee('Enviar acceso por WhatsApp');
        $response->assertSee('Contraseña temporal para');
    }

    public function test_whatsapp_message_contains_credentials(): void
    {
        [$company, $admin] = $this->individualContext();

        $this->actingAs($admin)
            ->post(route('platform.collectors.store', $company), [
                'name' => 'Mensaje Test',
                'email' => 'msg@test.com',
                'phone' => '88881234',
            ]);

        $tempPassword = session('collector_plain_password');
        $response = $this->actingAs($admin)
            ->get(route('platform.companies.show', $company));

        $response->assertSee('msg@test.com');
        $response->assertSee($tempPassword);
        $response->assertSee('primer ingreso');
    }

    /* ---------------------------------------------------------------------
     | OWNER VIEW: temp password + WhatsApp
     | --------------------------------------------------------------------- */

    public function test_owner_collectors_view_shows_temp_password_and_whatsapp(): void
    {
        [$company, $admin, $owner] = $this->cupoContext();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.collectors.store'), [
                'name' => 'Owner Test',
                'email' => 'ow@test.com',
                'phone' => '88887777',
            ]);

        $tempPassword = session('collector_plain_password');

        $response = $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.owner.collectors'));

        $response->assertOk();
        $response->assertSee('Acceso generado');
        $response->assertSee($tempPassword);
        $response->assertSee('wa.me/50688887777');
        $response->assertSee('Enviar acceso por WhatsApp');
    }

    /* ---------------------------------------------------------------------
     | SECURITY: isolation + no plain password persistence
     | --------------------------------------------------------------------- */

    public function test_plain_password_not_stored_in_database(): void
    {
        [$company, $admin] = $this->setupCompany();
        $service = app(CollectorManagementService::class);

        $result = $service->createCollector($company, [
            'name' => 'Hash Test',
            'email' => 'hash@test.com',
        ], $admin, true);

        $user = $result['user'];
        $plain = $result['plain_password'];

        $this->assertNotSame($plain, $user->password);
        $this->assertFalse(str_contains($user->password, $plain));
    }

    public function test_owner_cannot_regenerate_in_individual_mode(): void
    {
        [$company, $admin, $owner] = $this->individualContext();

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.owner.collectors.store'), [
                'name' => 'Test',
                'email' => 'test@test.com',
            ])->assertStatus(403);
    }

    public function test_guest_cannot_access_force_change(): void
    {
        $this->get(route('cobros.password.force'))->assertRedirect(route('cobros.login'));
    }

    /* ---------------------------------------------------------------------
     | EYE TOGGLE on force-change view
     | --------------------------------------------------------------------- */

    public function test_force_change_view_has_eye_toggle(): void
    {
        [$company, $admin, $collector] = $this->cupoContextWithCollector();
        $collector->update(['must_change_password' => true]);

        $response = $this->actingAs($collector)->get(route('cobros.password.force'));
        $response->assertOk();
        $response->assertSee('cobros-login-toggle');
        $response->assertSee('password_confirmation');
    }

    /* ---------------------------------------------------------------------
     | Login view: no hardcoded credentials
     | --------------------------------------------------------------------- */

    public function test_cobros_login_html_has_no_hardcoded_password_value(): void
    {
        $response = $this->get(route('cobros.login'));
        $html = $response->getContent();

        $response->assertOk();
        $this->assertStringNotContainsString('MVS2026', $html);

        // Password input must not carry a value= attribute
        preg_match('/<input[^>]*id="password"[^>]*>/i', $html, $matches);
        $this->assertNotEmpty($matches, 'Password input not found');
        $this->assertStringNotContainsString('value=', $matches[0], 'Password input must not have a value attribute');
        $this->assertStringNotContainsString('readonly', $matches[0], 'Password input must not be readonly');
        $this->assertStringContainsString('autocomplete="new-password"', $html);
    }

    /* ---------------------------------------------------------------------
     | Regenerate invalidates old temp password
     | --------------------------------------------------------------------- */

    public function test_regenerate_access_invalidates_old_temp_password(): void
    {
        [$company, $admin, $collector] = $this->cupoContextWithCollector();
        $service = app(CollectorManagementService::class);

        $oldHash = $collector->fresh()->password;

        // Regenerate
        $newPlain = $service->regenerateAccess($collector, $company);

        $this->assertSame(6, strlen($newPlain));
        // Old hash no longer matches
        $this->assertNotSame($oldHash, $collector->fresh()->password);
        // New password works
        $this->assertTrue(Hash::check($newPlain, $collector->fresh()->password));
        // Must change password is set
        $this->assertTrue($collector->fresh()->must_change_password);
    }

    /* ---------------------------------------------------------------------
     | Both modes: createCollector behavior
     | --------------------------------------------------------------------- */

    public function test_individual_mode_create_collector_auto_generates_password(): void
    {
        [$company, $admin] = $this->individualContext();
        $service = app(CollectorManagementService::class);

        $result = $service->createCollector($company, [
            'name' => 'Auto PW',
            'email' => 'autopw@test.com',
        ], $admin, true);

        $this->assertSame(6, strlen($result['plain_password']));
        $this->assertTrue($result['user']->must_change_password);
    }

    public function test_cupo_mode_create_collector_auto_generates_password(): void
    {
        [$company, $admin] = $this->cupoContext();
        $service = app(CollectorManagementService::class);

        $result = $service->createCollector($company, [
            'name' => 'Cupo Auto',
            'email' => 'cupoauto@test.com',
        ], $admin);

        $this->assertSame(6, strlen($result['plain_password']));
        $this->assertTrue($result['user']->must_change_password);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | --------------------------------------------------------------------- */

    private function setupCompany(string $name = 'Test'): array
    {
        $company = Company::create([
            'trade_name' => $name.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyCashSettingsProvisioner::class)->provision($company);

        return [$company, $admin];
    }

    private function setupOwner(Company $company): User
    {
        Permission::firstOrCreate(['name' => 'cobros.portal_dueno'], ['label' => 'Portal Dueño', 'module' => 'CobrOS', 'is_active' => true]);
        Permission::firstOrCreate(['name' => 'cobros.acceder'], ['label' => 'Acceder', 'module' => 'CobrOS', 'is_active' => true]);
        Permission::firstOrCreate(['name' => 'cobros.cobrar'], ['label' => 'Cobrar', 'module' => 'CobrOS', 'is_active' => true]);

        $owner = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Dueño'.uniqid(), 'is_active' => true]);
        $ownerPortal = Permission::where('name', 'cobros.portal_dueno')->first();
        $ownerAcceder = Permission::where('name', 'cobros.acceder')->first();
        $role->permissions()->attach([$ownerPortal->id, $ownerAcceder->id]);
        $owner->companies()->syncWithoutDetaching([$company->id => ['role_id' => $role->id]]);

        return $owner;
    }

    private function cupoContext(array $overrides = []): array
    {
        [$company, $admin] = $this->setupCompany();
        $owner = $this->setupOwner($company);

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'company_cupo',
                'collector_limit' => $overrides['collector_limit'] ?? 3,
                'admin_license_charged' => false,
            ]);

        $company->refresh();

        return [$company, $admin, $owner];
    }

    private function individualContext(): array
    {
        [$company, $admin] = $this->setupCompany();
        $owner = $this->setupOwner($company);

        $this->actingAs($admin)
            ->patch(route('platform.cobros-contract.update', $company), [
                'license_mode' => 'individual',
                'admin_license_charged' => false,
            ]);

        $company->refresh();

        return [$company, $admin, $owner];
    }

    private function cupoContextWithCollector(array $overrides = []): array
    {
        [$company, $admin, $owner] = $this->cupoContext($overrides);
        $service = app(CollectorManagementService::class);
        $result = $service->createCollector($company, [
            'name' => 'Cobrador Test',
            'email' => 'cobtest@test.com',
            'password' => 'password123',
        ], $admin);
        $collector = $result['user'];

        $company->refresh();

        return [$company, $admin, $collector, $owner];
    }

    private function individualContextWithCollector(): array
    {
        [$company, $admin, $owner] = $this->individualContext();

        $collector = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cobrador'.uniqid(), 'is_active' => true]);
        $p1 = Permission::firstOrCreate(['name' => 'cobros.acceder'], ['label' => 'Acceder', 'module' => 'CobrOS', 'is_active' => true]);
        $p2 = Permission::firstOrCreate(['name' => 'cobros.cobrar'], ['label' => 'Cobrar', 'module' => 'CobrOS', 'is_active' => true]);
        $role->permissions()->attach([$p1->id, $p2->id]);
        $collector->companies()->attach($company->id, ['role_id' => $role->id]);

        $company->refresh();

        return [$company, $admin, $collector, $owner];
    }
}
