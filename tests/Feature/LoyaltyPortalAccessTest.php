<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyPortalAccess;
use App\Models\LoyaltySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\Loyalty\LoyaltyPortalAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_staff_generates_unique_link_and_token_is_stored_only_as_hash(): void
    {
        [$company, $branch] = $this->companyContext('Acceso A');
        $user = $this->user($company, $branch, true);
        $customer = $this->customer($company, 'CLIENTE-ENLACE', [
            'identification' => '1122334455',
            'email' => 'cliente.enlace@correo.com',
            'phone' => '88887777',
        ]);

        $first = $this->generateFor($user, $company, $branch, $customer);
        $second = $this->generateFor($user, $company, $branch, $customer);

        // Único e impredecible: cada generación produce un token distinto.
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertGreaterThanOrEqual(40, strlen($first['token']));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $first['token']);

        // Sin datos personales ni IDs internos en el enlace: la ruta contiene
        // únicamente el token aleatorio como último segmento.
        foreach ([$customer->identification, $customer->email, $customer->phone, $customer->name] as $sensitive) {
            $this->assertStringNotContainsString((string) $sensitive, $first['url']);
        }
        $path = parse_url($first['url'], PHP_URL_PATH);
        $this->assertSame($first['token'], basename($path));
        $this->assertNotSame((string) $customer->id, $first['token']);
        $this->assertStringNotContainsString('cliente', strtolower($first['token']));

        // Persistido solo como hash; el token en claro no está en la base de datos.
        $access = LoyaltyPortalAccess::query()->where('company_id', $company->id)->whereNull('revoked_at')->sole();
        $this->assertSame(hash('sha256', $second['token']), $access->token_hash);
        $this->assertNotSame($second['token'], $access->token_hash);
        $this->assertDatabaseMissing('loyalty_portal_accesses', ['token_hash' => $second['token']]);
        $this->assertSame(1, LoyaltyPortalAccess::query()->where('customer_id', $customer->id)->whereNull('revoked_at')->count());
    }

    public function test_public_link_opens_the_correct_portal_without_staff_session(): void
    {
        [$company, $branch] = $this->companyContext('Portal publico');
        $this->setting($company);
        $staff = $this->user($company, $branch, true);
        $customer = $this->customer($company, 'CLIENTE-PUBLICO');
        $other = $this->customer($company, 'CLIENTE-AJENO');

        $service = app(LoyaltyAccountService::class);
        $account = $service->getOrCreateAccount($customer, $company);
        $service->addPoints($account, '320.0000', LoyaltyMovement::TYPE_PURCHASE, ['branch' => $branch, 'description' => 'Compra con enlace publico']);
        $otherAccount = $service->getOrCreateAccount($other, $company);
        $service->addPoints($otherAccount, '999.0000', LoyaltyMovement::TYPE_PURCHASE, ['branch' => $branch, 'description' => 'Dato de otro cliente']);

        ['url' => $url] = $this->generateFor($staff, $company, $branch, $customer);

        // Sin autenticación ni sesión de empresa: el token resuelve empresa y cliente.
        $this->app['auth']->forgetGuards();
        $this->get($url)
            ->assertOk()
            ->assertSee($customer->name)
            ->assertSee($company->trade_name)
            ->assertSee('Saldo actual')
            ->assertSee('320 puntos')
            ->assertSee('Compra con enlace publico')
            ->assertSee('Historial de movimientos')
            ->assertDontSee('Dato de otro cliente');

        $access = LoyaltyPortalAccess::query()->whereNull('revoked_at')->sole();
        $this->assertNotNull($access->fresh()->last_used_at);
    }

    public function test_invalid_revoked_and_regenerated_tokens_cannot_access(): void
    {
        [$company, $branch] = $this->companyContext('Revocacion');
        $staff = $this->user($company, $branch, true);
        $customer = $this->customer($company, 'CLIENTE-REVOCAR');

        ['url' => $oldUrl, 'token' => $oldToken] = $this->generateFor($staff, $company, $branch, $customer);

        $this->get($oldUrl)->assertOk();
        $this->get(route('loyalty.portal.access', ['token' => str_repeat('x', 60)]))->assertNotFound();
        $this->get(route('loyalty.portal.access', ['token' => $oldToken.'x']))->assertNotFound();

        // Regenerar invalida el enlace anterior.
        ['url' => $newUrl] = $this->generateFor($staff, $company, $branch, $customer);
        $this->get($oldUrl)->assertNotFound();
        $this->get($newUrl)->assertOk();

        // Revocar cierra el acceso definitivamente.
        $this->actingAs($staff)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->patch(route('loyalty.accesses.revoke', $customer))
            ->assertRedirect();
        $this->get($newUrl)->assertNotFound();
        $this->assertNull(app(LoyaltyPortalAccessService::class)->activeFor($customer, $company));
    }

    public function test_links_and_administration_are_company_scoped(): void
    {
        [$companyA, $branchA] = $this->companyContext('Enlace A');
        [$companyB, $branchB] = $this->companyContext('Enlace B');
        $staffA = $this->user($companyA, $branchA, true);
        $customerA = $this->customer($companyA, 'CLIENTE-A');
        $customerB = $this->customer($companyB, 'CLIENTE-B');
        $this->setting($companyB);
        $staffB = $this->user($companyB, $branchB, true);
        app(LoyaltyAccountService::class)->getOrCreateAccount($customerB, $companyB);

        ['url' => $urlB] = $this->generateFor($staffB, $companyB, $branchB, $customerB);

        // El enlace de B resuelve a B aunque nadie tenga sesión activa.
        $this->app['auth']->forgetGuards();
        $this->get($urlB)
            ->assertOk()
            ->assertSee($customerB->name)
            ->assertSee($companyB->trade_name)
            ->assertDontSee($companyA->trade_name);

        // La administración de A solo ve accesos de A y no puede revocar al cliente de B.
        $this->actingAs($staffA)->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->get(route('loyalty.accesses.index'))
            ->assertOk()
            ->assertViewHas('accesses', fn ($accesses) => $accesses->count() === 0)
            ->assertDontSee($customerB->name);

        $this->actingAs($staffA)->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->patch(route('loyalty.accesses.revoke', $customerB))
            ->assertNotFound();
        $this->assertNotNull(LoyaltyPortalAccess::query()->where('customer_id', $customerB->id)->whereNull('revoked_at')->first());
    }

    public function test_administration_requires_authentication_and_permission(): void
    {
        [$company, $branch] = $this->companyContext('Permisos acceso');
        $authorized = $this->user($company, $branch, true);
        $unauthorized = $this->user($company, $branch, false);
        $customer = $this->customer($company, 'CLIENTE-PERMISO');

        $session = ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];

        $this->get(route('loyalty.accesses.index'))->assertRedirect(route('login'));

        $this->actingAs($unauthorized)->withSession($session)->get(route('loyalty.accesses.index'))->assertForbidden();
        $this->actingAs($unauthorized)->withSession($session)->post(route('loyalty.accesses.store'), ['customer_id' => $customer->id])->assertForbidden();
        $this->actingAs($unauthorized)->withSession($session)->patch(route('loyalty.accesses.revoke', $customer))->assertForbidden();

        $this->actingAs($authorized)->withSession($session)->get(route('loyalty.accesses.index'))->assertOk();
    }

    public function test_qr_is_generated_locally_for_the_secure_link_without_external_services(): void
    {
        [$company, $branch] = $this->companyContext('QR');
        $staff = $this->user($company, $branch, true);
        $customer = $this->customer($company, 'CLIENTE-QR', [
            'identification' => '9988776655',
            'email' => 'cliente.qr@correo.com',
            'phone' => '77776666',
        ]);
        $service = app(LoyaltyPortalAccessService::class);

        // La generación local de QR está disponible (chillerlan/php-qrcode).
        $this->assertTrue($service->qrSupported());

        ['url' => $url] = $this->generateFor($staff, $company, $branch, $customer);

        // El enlace y su QR se entregan juntos en la misma respuesta.
        $content = (string) $this->actingAs($staff)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('loyalty.accesses.index'))
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee($url)
            ->getContent();

        // Ninguna API externa de QR recibe el token del cliente.
        foreach (['chart.googleapis', 'quickchart', 'api.qrserver', 'qrserver.com'] as $external) {
            $this->assertStringNotContainsString($external, $content);
        }

        // El QR mostrado no contiene datos personales del cliente.
        foreach ([$customer->identification, $customer->email, $customer->phone] as $sensitive) {
            $this->assertStringNotContainsString((string) $sensitive, $content);
        }

        // El servicio genera un SVG local a partir del propio enlace.
        $this->assertStringStartsWith('<svg', $service->qrSvg($url));
    }

    public function test_existing_staff_portal_route_keeps_working(): void
    {
        [$company, $branch] = $this->companyContext('Ruta staff');
        $this->setting($company);
        $staff = $this->user($company, $branch, true, 'fidelidad.ver');
        $customer = $this->customer($company, 'CLIENTE-STAFF');

        $this->actingAs($staff)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('loyalty.portal.show', $customer))
            ->assertOk()
            ->assertSee($customer->name)
            ->assertSee('Hecho con MVS Commerce');
    }

    private function generateFor(User $user, Company $company, Branch $branch, Customer $customer): array
    {
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('loyalty.accesses.store'), ['customer_id' => $customer->id]);

        $response->assertRedirect();
        $url = $response->getSession()->get('portal_url');
        $this->assertIsString($url);

        return ['url' => $url, 'token' => substr($url, (int) strrpos($url, '/') + 1)];
    }

    private function customer(Company $company, string $name, array $extra = []): Customer
    {
        return Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => $name, 'credit_limit' => 0, 'is_active' => true] + $extra);
    }

    private function setting(Company $company): LoyaltySetting
    {
        return LoyaltySetting::create(['company_id' => $company->id, 'is_active' => true, 'earning_percentage' => '5.0000', 'point_value' => '1.0000']);
    }

    private function companyContext(string $name): array
    {
        $company = Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);

        return [$company, $this->branch($company, 'Principal')];
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => strtoupper(substr(uniqid(), -6)), 'is_active' => true]);
    }

    private function user(Company $company, Branch $branch, bool $authorized, string $permissionName = 'fidelidad.portal'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        if ($authorized) {
            $permission = Permission::firstOrCreate(['name' => $permissionName], ['label' => $permissionName, 'module' => 'Fidelidad', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }
}
