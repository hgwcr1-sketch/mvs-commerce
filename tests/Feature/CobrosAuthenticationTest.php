<?php

namespace Tests\Feature;

use App\Http\Middleware\Cobros\MarkEntry;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CobrosAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_login_has_only_cobros_branding_and_shared_csrf_form(): void
    {
        $response = $this->get(route('cobros.login'))->assertOk()
            ->assertSee('Bienvenido a MVS CobrOS')->assertSee('logo-mvs-cobros.png')
            ->assertSee('name="_token"', false)->assertSee('Recordarme')
            ->assertSee('action="'.route('cobros.login.store').'"', false)
            ->assertDontSee('MVS Commerce')->assertDontSee('href="'.route('login').'"', false)
            ->assertDontSee('href="'.route('password.request').'"', false);
        if ($fixture = getenv('COBROS_LOGIN_HTML')) {
            file_put_contents($fixture, $response->getContent());
        }
    }

    public function test_guest_redirect_is_scoped_to_cobros_including_expired_logout(): void
    {
        foreach (['index', 'owner.dashboard', 'supervisor.index', 'collector.index'] as $route) {
            $this->get(route('cobros.'.$route))->assertRedirect(route('cobros.login'));
        }
        $this->post(route('cobros.logout'))->assertRedirect(route('cobros.login'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Bienvenido a MVS Commerce')
            ->assertDontSee('Bienvenido a MVS CobrOS');
    }

    public function test_valid_login_reuses_identity_regenerates_session_and_ignores_intended_urls(): void
    {
        $user = User::factory()->create(['password' => 'Secure123', 'is_active' => true]);
        $hash = $user->password;
        $this->get(route('cobros.login'));
        $this->withSession(['sentinel' => 'retained', 'url.intended' => 'https://outside.example/']);
        $oldSession = session()->getId();
        $this->withCookie(config('session.cookie'), $oldSession);
        $oldToken = session()->token();
        $this->post(route('cobros.login.store'), ['email' => $user->email, 'password' => 'Secure123', 'remember' => '1'])
            ->assertRedirect(route('cobros.index'))->assertSessionHas('sentinel', 'retained')
            ->assertSessionMissing('url.intended')->assertCookie(Auth::guard()->getRecallerName());
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_existing_session_is_preserved_on_login_get_and_post(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['password' => 'Secure123', 'is_active' => true]);
        $this->actingAs($user)->withSession(['sentinel' => 'keep']);
        $session = session()->getId();
        $this->withCookie(config('session.cookie'), $session);
        $this->get(route('cobros.login'))->assertRedirect(route('cobros.index'));
        $this->post(route('cobros.login.store'), ['email' => $other->email, 'password' => 'Secure123'])
            ->assertRedirect(route('cobros.index'))->assertSessionHas('sentinel', 'keep');
        $this->assertAuthenticatedAs($user);
        $this->assertSame($session, session()->getId());
    }

    public function test_invalid_unknown_and_inactive_users_receive_the_same_error(): void
    {
        $active = User::factory()->create(['password' => 'Secure123', 'is_active' => true]);
        $inactive = User::factory()->create(['password' => 'Secure123', 'is_active' => false]);
        foreach ([[$active->email, 'wrong'], [$inactive->email, 'Secure123'], ['missing@example.test', 'Secure123']] as [$email, $password]) {
            $this->from(route('login'))->post(route('cobros.login.store'), compact('email', 'password'))
                ->assertRedirect(route('cobros.login'))
                ->assertSessionHasErrors(['email' => 'Las credenciales son incorrectas o el usuario está inactivo.'])
                ->assertSessionMissing('_old_input.password');
            $this->assertGuest();
        }
    }

    public function test_malformed_credentials_return_validation_errors_without_render_failure(): void
    {
        foreach ([[], ['email' => ['bad'], 'password' => ['bad']], ['email' => 'invalid', 'password' => '']] as $payload) {
            $this->post(route('cobros.login.store'), $payload)->assertRedirect(route('cobros.login'))
                ->assertSessionHasErrors(['email', 'password'])->assertSessionMissing('_old_input.password');
            $this->get(route('cobros.login'))->assertOk();
            $this->assertGuest();
        }
    }

    public function test_failed_attempt_limit_is_shared_with_commerce(): void
    {
        $user = User::factory()->create(['password' => 'Secure123', 'is_active' => true]);
        for ($i = 0; $i < 5; $i++) {
            $route = $i % 2 ? 'login.store' : 'cobros.login.store';
            $this->from(route('login'))->post(route($route), ['email' => $user->email, 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }
        $this->post(route('cobros.login.store'), ['email' => $user->email, 'password' => 'Secure123'])
            ->assertRedirect(route('cobros.login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(5, RateLimiter::attempts(strtolower($user->email).'|127.0.0.1'));
    }

    public function test_route_throttle_blocks_excessive_requests(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('cobros.login.store'), ['email' => "unknown{$i}@example.test", 'password' => 'wrong'])
                ->assertRedirect(route('cobros.login'));
        }
        $this->post(route('cobros.login.store'), ['email' => 'another@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    #[DataProvider('profiles')]
    public function test_login_then_entry_respects_existing_operational_permissions(string $permission, string $landing): void
    {
        [$company, $user] = $this->context(['cobros.acceder', $permission]);
        $before = $this->authorizationSnapshot($company);
        $this->post(route('cobros.login.store'), ['email' => $user->email, 'password' => 'Secure123'])
            ->assertRedirect(route('cobros.index'));
        $this->get(route('cobros.index'))->assertRedirect(route('cobros.'.$landing));
        $this->get(route('cobros.'.$landing))->assertOk()
            ->assertSee('action="'.route('cobros.logout').'"', false)
            ->assertDontSee('action="'.route('logout').'"', false);
        $this->assertSame($before, $this->authorizationSnapshot($company));
    }

    public static function profiles(): array
    {
        return [
            ['cobros.portal_dueno', 'owner.dashboard'],
            ['cobros.supervisar', 'supervisor.index'],
            ['cobros.cobrar', 'collector.index'],
        ];
    }

    public function test_login_does_not_grant_permissions_or_enable_modules(): void
    {
        [$company, $user] = $this->context([]);
        $before = $this->authorizationSnapshot($company);
        $this->post(route('cobros.login.store'), ['email' => $user->email, 'password' => 'Secure123'])->assertRedirect(route('cobros.index'));
        $this->get(route('cobros.index'))->assertForbidden();
        $this->assertSame($before, $this->authorizationSnapshot($company));
    }

    public function test_cobros_logout_invalidates_shared_session_and_returns_to_cobros(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->withSession(['active_company_id' => 999, 'sentinel' => 'clear']);
        $session = session()->getId();
        $this->withCookie(config('session.cookie'), $session);
        $token = session()->token();
        $this->post(route('cobros.logout'))->assertRedirect(route('cobros.login'))
            ->assertSessionMissing('sentinel')->assertSessionMissing('active_company_id');
        $this->assertGuest();
        $this->assertNotSame($session, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_commerce_success_and_logout_destinations_remain_unchanged(): void
    {
        [$company, $user] = $this->context([]);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Secure123'])->assertRedirect('/dashboard');
        $this->post(route('logout'))->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_platform_admin_commerce_redirect_is_preserved(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true, 'is_active' => true, 'password' => 'Secure123']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Secure123'])->assertRedirect(route('platform.index'));
    }

    public function test_csrf_is_enforced_for_login_and_logout(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $user = User::factory()->create(['is_active' => true, 'password' => 'Secure123']);
        $this->get(route('cobros.login'))->assertOk();
        $credentials = ['email' => $user->email, 'password' => 'Secure123'];
        $this->post(route('cobros.login.store'), $credentials)->assertStatus(419);
        $this->post(route('cobros.login.store'), [...$credentials, '_token' => session()->token()])->assertRedirect(route('cobros.index'));
        $this->post(route('cobros.logout'))->assertStatus(419);
        $this->assertAuthenticatedAs($user);
        $this->post(route('cobros.logout'), ['_token' => session()->token()])->assertRedirect(route('cobros.login'));
        $this->assertGuest();
    }

    private function context(array $permissions): array
    {
        $company = Company::create(['trade_name' => 'Empresa CobrOS', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $user = User::factory()->create(['password' => 'Secure123', 'is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Perfil CobrOS', 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);

        return [$company, $user];
    }

    private function authorizationSnapshot(Company $company): array
    {
        return [
            DB::table('company_user')->orderBy('user_id')->get()->toJson(),
            Permission::orderBy('id')->get()->toJson(),
            Role::with('permissions')->orderBy('id')->get()->toJson(),
            $company->modules()->orderBy('id')->get()->toJson(),
            $company->license()->first()->toJson(),
        ];
    }

    /* ------------------------------------------------------------------
     | COBROS ISOLATION: login/password/portal NEVER redirect to Commerce
     | ------------------------------------------------------------------ */

    public function test_cobros_login_never_redirects_to_commerce_onboarding(): void
    {
        [$company, $user] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);

        $this->post(route('cobros.login.store'), [
            'email' => $user->email,
            'password' => 'Secure123',
        ])->assertRedirect(route('cobros.index'));

        $this->get(route('cobros.index'))->assertRedirect(route('cobros.owner.dashboard'));

        $this->get(route('cobros.owner.dashboard'))->assertOk();
    }

    public function test_cobros_password_change_never_redirects_to_commerce(): void
    {
        [$company, $user] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);
        $user->update(['must_change_password' => true, 'password' => 'Temp1234']);

        $this->post(route('cobros.login.store'), [
            'email' => $user->email,
            'password' => 'Temp1234',
        ])->assertRedirect(route('cobros.password.force'));

        $this->post(route('cobros.password.force.store'), [
            'password' => 'NewSecure789',
            'password_confirmation' => 'NewSecure789',
        ])->assertRedirect(route('cobros.index'));

        $this->get(route('cobros.index'))->assertRedirect(route('cobros.owner.dashboard'));
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_cobros_owner_resolves_to_owner_portal_not_commerce_dashboard(): void
    {
        [$company, $user] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);

        $this->post(route('cobros.login.store'), [
            'email' => $user->email,
            'password' => 'Secure123',
        ]);

        $this->get(route('cobros.index'))->assertRedirect(route('cobros.owner.dashboard'));
        $this->get(route('cobros.owner.dashboard'))->assertOk()
            ->assertSee('Cartera del dueño')
            ->assertSee('MVS CobrOS');
    }

    public function test_cobros_collector_resolves_to_collector_portal_not_commerce_dashboard(): void
    {
        [$company, $user] = $this->context(['cobros.acceder', 'cobros.cobrar']);

        $this->post(route('cobros.login.store'), [
            'email' => $user->email,
            'password' => 'Secure123',
        ]);

        $this->get(route('cobros.index'))->assertRedirect(route('cobros.collector.index'));
        $this->get(route('cobros.collector.index'))->assertOk()
            ->assertSee('Mi cartera')
            ->assertDontSee('Dashboard general');
    }

    #[DataProvider('profiles')]
    public function test_login_from_cobros_never_reaches_commerce_dashboard(string $permission, string $landing): void
    {
        [$company, $user] = $this->context(['cobros.acceder', $permission]);

        // Cadena real desde /cobros/login con una intención de Commerce previa
        // (el tipo de url.intended que dejaría un intento de entrar a /dashboard).
        $this->withSession(['url.intended' => route('dashboard')]);

        $login = $this->post(route('cobros.login.store'), ['email' => $user->email, 'password' => 'Secure123']);
        $login->assertRedirect(route('cobros.index'));
        $login->assertSessionMissing('url.intended');

        $entry = $this->get(route('cobros.index'));
        $entry->assertRedirect(route('cobros.'.$landing));

        // Ningún salto de la cadena sale de /cobros.
        foreach ([$login, $entry] as $response) {
            $location = $response->headers->get('Location');
            $this->assertNotNull($location, 'La cadena de login debe emitir Location.');
            $this->assertStringStartsWith(url('/cobros'), $location, 'Location fuera de CobrOS: '.$location);
        }

        // URL final: layout CobrOS, nunca el dashboard/sidebar de Commerce.
        $this->get(route('cobros.'.$landing))->assertOk()
            ->assertSee('manifest-cobros.json', false)
            ->assertSee('MVS CobrOS')
            ->assertDontSee('Fidelización')
            ->assertDontSee('MVS Commerce')
            ->assertDontSee('href="'.route('dashboard').'"', false)
            ->assertDontSee('href="'.route('login').'"', false);
    }

    public function test_cobros_user_without_company_gets_403_not_commerce_onboarding(): void
    {
        $user = User::factory()->create(['is_active' => true, 'password' => 'Secure123']);
        // No company attached at all

        $this->post(route('cobros.login.store'), [
            'email' => $user->email,
            'password' => 'Secure123',
        ]);

        $this->get(route('cobros.index'))->assertForbidden();
    }

    public function test_cobros_active_company_middleware_never_redirects_to_empresa_create(): void
    {
        [$company, $user] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);

        // Clear session to force auto-resolution
        $this->post(route('cobros.login.store'), [
            'email' => $user->email,
            'password' => 'Secure123',
        ]);

        $this->session(['active_company_id' => null, 'active_branch_id' => null]);

        $response = $this->get(route('cobros.index'));
        $response->assertRedirect(route('cobros.owner.dashboard'));
    }

    /* ------------------------------------------------------------------
     | ENTRADA CobrOS + puerta Commerce: nunca terminar en /dashboard
     ------------------------------------------------------------------ */

    public function test_commerce_login_returns_to_cobros_after_an_explicit_cobros_entry(): void
    {
        [$company, $user] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);
        $this->withCookie(MarkEntry::COOKIE, MarkEntry::VALUE);

        $login = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Secure123']);
        $login->assertRedirect(route('cobros.index'));
        $login->assertCookieExpired(MarkEntry::COOKIE);

        $this->get(route('cobros.index'))->assertRedirect(route('cobros.owner.dashboard'));
        $this->get(route('cobros.owner.dashboard'))->assertOk()
            ->assertSee('manifest-cobros.json', false)
            ->assertDontSee('Fidelización');
    }

    public function test_commerce_login_without_cobros_entry_keeps_the_dashboard(): void
    {
        [$company, $user] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Secure123'])
            ->assertRedirect('/dashboard');
    }

    public function test_commerce_login_stays_on_dashboard_when_the_user_cannot_operate_cobros(): void
    {
        [$company, $user] = $this->context([]);
        $this->withCookie(MarkEntry::COOKIE, MarkEntry::VALUE);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Secure123'])
            ->assertRedirect('/dashboard');
    }

    public function test_cobros_entry_does_not_hijack_deep_commerce_destinations(): void
    {
        [$company, $user] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);
        $this->withSession(['url.intended' => '/pos']);
        $this->withCookie(MarkEntry::COOKIE, MarkEntry::VALUE);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Secure123'])
            ->assertRedirect('/pos');
    }

    public function test_commerce_login_page_offers_an_explicit_cobros_access_link(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('Acceso CobrOS')
            ->assertSee('href="'.route('cobros.login').'"', false)
            ->assertDontSee('Bienvenido a MVS CobrOS');
    }

    public function test_cobros_pages_mark_the_entry_for_the_next_login(): void
    {
        $this->get(route('cobros.login'))->assertOk()
            ->assertSee('id="cobros-install"', false)
            ->assertCookie(MarkEntry::COOKIE, MarkEntry::VALUE);
    }
}
