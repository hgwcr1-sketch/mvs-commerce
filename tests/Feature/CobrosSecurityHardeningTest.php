<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosAssignment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 5C — seguridad endurecida CobrOS.
 *
 * Casos negativos de: rate limiting en rutas financieras, CSRF en endpoints
 * financieros, revocación de acceso (usuario/rol/permiso/módulo/licencia),
 * aislamiento tenant en escrituras y revisión 5B (caché sin datos
 * sensibles, borradores aislados por owner y sin credenciales; token de
 * idempotencia preservado).
 */
class CobrosSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_routes_declare_rate_limits(): void
    {
        $expected = [
            'cobros.collector.payments.store' => 'throttle:30,1',
            'cobros.collector.payments.annul' => 'throttle:10,1',
            'cobros.password.force.store' => 'throttle:10,1',
            'cobros.login.store' => 'throttle:10,1',
        ];
        foreach ($expected as $name => $throttle) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains($throttle, $route->gatherMiddleware(), $name);
        }
        // Las rutas financieras conservan permiso + stack web (CSRF/sesión).
        foreach (['cobros.collector.payments.store', 'cobros.collector.payments.annul'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('permission:cobros.'.($name === 'cobros.collector.payments.annul' ? 'anular' : 'cobrar'), $middleware);
            $this->assertContains('web', $middleware);
        }
    }

    public function test_annulment_endpoint_is_rate_limited_after_ten_attempts(): void
    {
        [$c, $b, $u] = $this->context(['cobros.acceder', 'cobros.cobrar', 'cobros.anular']);
        $payload = ['reason' => 'Motivo de prueba suficientemente largo', 'client_token' => (string) Str::uuid()];

        // 10 intentos pasan el throttle (fallan por recibo inexistente → 404).
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
                route('cobros.collector.payments.annul', 'RCB-00000001'),
                $payload
            )->assertNotFound();
        }
        // El 11.º queda bloqueado por el límite, sin llegar al controlador.
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])->post(
            route('cobros.collector.payments.annul', 'RCB-00000001'),
            $payload
        )->assertStatus(429);
        $this->assertDatabaseCount('cobros_payment_reversals', 0);
    }

    public function test_financial_writes_require_csrf_token(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        [$c, $b, $u] = $this->context();
        $account = $this->account($c, $b, $u, '500.0000');
        $payload = [
            'amount' => '100.00', 'payment_method_id' => $this->method($c)->id,
            'reference' => null, 'notes' => null, 'client_token' => (string) Str::uuid(), 'confirm' => '1',
        ];

        // Sin token: 419 y cero escrituras (CSRF del stack web intacto).
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.payments.store', $account), $payload)
            ->assertStatus(419);
        $this->assertDatabaseCount('accounts_receivable_payments', 0);

        // Con token: pasa el CSRF y llega al flujo normal (redirect).
        $this->actingAs($u)->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.payments.store', $account), [...$payload, '_token' => session()->token()])
            ->assertRedirect();
        $this->assertDatabaseCount('accounts_receivable_payments', 1);
    }

    public function test_revoked_user_role_permission_and_module_are_rejected_on_financial_write(): void
    {
        [$c, $b, $u] = $this->context();
        $account = $this->account($c, $b, $u, '500.0000');
        $payload = [
            'amount' => '100.00', 'payment_method_id' => $this->method($c)->id,
            'reference' => null, 'notes' => null, 'client_token' => (string) Str::uuid(), 'confirm' => '1',
        ];

        // 1) Usuario desactivado tras crear la sesión → 403 (EnsureActiveUser).
        $u->update(['is_active' => false]);
        $this->actingAs($u->fresh())->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.payments.store', $account), $payload)->assertForbidden();
        $u->update(['is_active' => true]);

        // 2) Rol desactivado → 403 (hasPermission exige rol activo).
        $role = $u->roleInCompany($c);
        $role->update(['is_active' => false]);
        $this->actingAs($u->fresh())->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.payments.store', $account), $payload)->assertForbidden();
        $role->update(['is_active' => true]);

        // 3) Permiso revocado → 403.
        $role->permissions()->detach(Permission::where('name', 'cobros.cobrar')->value('id'));
        $this->actingAs($u->fresh())->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.payments.store', $account), $payload)->assertForbidden();

        // 4) Módulo deshabilitado → 403.
        $role->permissions()->attach(Permission::firstOrCreate(['name' => 'cobros.cobrar'], ['label' => 'cobros.cobrar', 'module' => 'CobrOS', 'is_active' => true]));
        $c->modules()->where('module_key', 'cobros')->update(['is_enabled' => false]);
        $this->actingAs($u->fresh())->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.payments.store', $account), $payload)->assertForbidden();
        $c->modules()->where('module_key', 'cobros')->update(['is_enabled' => true]);

        // 5) Licencia suspendida → redirige a license.status.
        $c->license()->update(['status' => 'suspended']);
        $this->actingAs($u->fresh())->withSession(['active_company_id' => $c->id])
            ->post(route('cobros.collector.payments.store', $account), $payload)
            ->assertRedirect(route('license.status'));

        // Ninguna revocación escribió dinero.
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertSame('500.0000', (string) $account->fresh()->balance_due);
    }

    public function test_tenant_context_cannot_be_hijacked_to_pay_a_foreign_account(): void
    {
        [$c, $b, $u] = $this->context();
        $account = $this->account($c, $b, $u, '300.0000');
        [$fc, $fb, $fu] = $this->context();
        $foreign = $this->account($fc, $fb, $fu, '700.0000');

        // Sesión con empresa ajena: EnsureActiveCompany la corrige; el cobro
        // contra la cuenta ajena sigue siendo 404.
        $this->actingAs($u)->withSession(['active_company_id' => $fc->id])->post(
            route('cobros.collector.payments.store', $foreign),
            [
                'amount' => '50.00', 'payment_method_id' => $this->method($c)->id,
                'reference' => null, 'notes' => null, 'client_token' => (string) Str::uuid(), 'confirm' => '1',
            ]
        )->assertNotFound();

        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertSame('700.0000', (string) $foreign->fresh()->balance_due);
        $this->assertSame('300.0000', (string) $account->fresh()->balance_due);
    }

    public function test_offline_shell_has_no_sensitive_cache_and_scopes_drafts_by_owner(): void
    {
        $root = dirname(__DIR__, 2);
        $sw = (string) file_get_contents($root.'/public/sw-cobros.js');
        $pwa = (string) file_get_contents($root.'/resources/js/cobros-pwa.js');
        $layout = (string) file_get_contents($root.'/resources/views/cobros/layout.blade.php');

        // Caché solo de assets públicos: jamás HTML dinámico/autenticado.
        $this->assertStringContainsString("'/manifest-cobros.json'", $sw);
        $this->assertStringContainsString("'/icons/cobros/icon-192x192.png'", $sw);
        // Precache declarado: solo manifest + iconos (assets públicos),
        // jamás el HTML dinámico de /cobros ni respuestas autenticadas.
        preg_match('/addAll\((\[[^;]*\])/', $sw, $m);
        $this->assertNotEmpty($m[1] ?? null, 'install declara su precache');
        $this->assertStringContainsString("'/manifest-cobros.json'", $m[1]);
        $this->assertStringContainsString("'/icons/cobros/icon-192x192.png'", $m[1]);
        $this->assertStringContainsString("'/icons/cobros/icon-512x512.png'", $m[1]);
        // Jamás precachea páginas autenticadas de /cobros.
        $this->assertStringNotContainsString("'/cobros", $m[1]);
        $this->assertStringNotContainsString('"/cobros', $m[1]);
        $this->assertStringNotContainsString('http', $m[1]);
        // Nunca se hace put de respuestas dinámicas en Cache Storage.
        $this->assertStringNotContainsString('cache.put(', $sw);
        $this->assertStringNotContainsString('caches.put(', $sw);

        // Borradores: aislados por owner y borrados al cerrar sesión.
        $this->assertStringContainsString('cobros:clear-drafts', $sw);
        $this->assertStringContainsString('owner', $sw);
        $this->assertStringContainsString('form[action*="cobros/logout"]', $pwa);
        $this->assertStringContainsString('cobros:clear-drafts', $pwa);
        $this->assertStringContainsString('data-cobros-owner', $layout);
        $this->assertStringContainsString('auth()->id()', $layout);

        // Sin credenciales persistidas; el token de idempotencia se conserva.
        foreach (['_token', 'password', 'authorization', 'cookie'] as $credential) {
            $this->assertStringContainsString("'".$credential."'", $sw);
        }
        $this->assertStringNotContainsString("'client_token'", $sw);
        $this->assertStringNotContainsString("'client_token'", $pwa);

        // El shell nunca confirma pagos offline.
        $this->assertStringNotContainsString('payments.store', $sw);
        $this->assertStringNotContainsString('payments.store', $pwa);
        $this->assertStringContainsString('solo se confirman en línea', $sw);
    }

    private function method(Company $c): PaymentMethod
    {
        return PaymentMethod::query()->where('company_id', $c->id)->where('code', 'cash')->firstOrFail();
    }

    private function account(Company $c, Branch $b, User $u, string $balance): AccountReceivable
    {
        $customer = Customer::create(['company_id' => $c->id, 'customer_type' => 'individual', 'name' => 'Cliente '.uniqid(), 'is_active' => true]);
        CobrosAssignment::create([
            'company_id' => $c->id, 'customer_id' => $customer->id,
            'collector_user_id' => $u->id, 'assigned_by' => $u->id, 'assigned_at' => now(),
        ]);
        $sale = Sale::create(['company_id' => $c->id, 'branch_id' => $b->id, 'user_id' => $u->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);

        return AccountReceivable::create(['company_id' => $c->id, 'branch_id' => $b->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id, 'issued_at' => today()->subMonth(), 'due_date' => today()->addDays(7), 'original_amount' => $balance, 'balance_due' => $balance, 'status' => 'pending', 'currency_code' => 'CRC']);
    }

    private function context(array $permissions = ['cobros.acceder', 'cobros.cobrar']): array
    {
        $company = Company::create(['trade_name' => 'Cobros '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal '.substr(uniqid(), -5), 'code' => 'S'.random_int(1, 99), 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyLicenseService::class)->updateModules($company, User::factory()->create(['is_platform_admin' => true, 'is_active' => true]), ['cobros']);
        app(PaymentMethodProvisioner::class)->provision($company);

        return [$company->fresh(), $branch, $user];
    }
}
