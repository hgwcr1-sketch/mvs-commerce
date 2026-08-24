<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Loyalty\LoyaltyPortalAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyPortalAccessQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_qr_is_generated_for_the_secure_link_and_encodes_exactly_that_link(): void
    {
        [$company, $branch] = $this->companyContext('QR contenido');
        $staff = $this->user($company, $branch, true);
        $customer = $this->customer($company, 'CLIENTE-QR-CONTENIDO', [
            'identification' => '1020304050',
            'email' => 'qr.contenido@correo.com',
            'phone' => '66665555',
        ]);
        $service = app(LoyaltyPortalAccessService::class);

        $this->assertTrue($service->qrSupported());

        ['token' => $token, 'url' => $url] = $this->generateViaService($service, $customer, $company, $staff);

        // QR válido: SVG local para el enlace seguro vigente.
        $svg = $service->qrSvg($url);
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('viewBox', $svg);

        // Determinista por enlace: el mismo enlace produce exactamente el mismo QR
        // (así se prueba que el QR impreso corresponde a ese enlace y no a otro dato).
        $this->assertSame($svg, $service->qrSvg($url));

        // Un enlace distinto produce un QR distinto: el QR no es genérico ni constante.
        $this->assertNotSame($svg, $service->qrSvg($this->urlFor(Str::random(60))));

        // El QR no contiene identificación, email, teléfono, nombre, el token
        // en claro ni su hash. (El enlace mismo ya está probado sin IDs internos
        // ni datos personales en LoyaltyPortalAccessTest; aquí se comprueba el
        // contenido del SVG del QR.)
        foreach ([$customer->identification, $customer->email, $customer->phone, $customer->name, $token, hash('sha256', $token)] as $sensitive) {
            $this->assertStringNotContainsString((string) $sensitive, $svg);
        }

        // Nada del QR ni del token en claro se persiste: solo el hash del token.
        $access = LoyaltyPortalAccess::query()->whereNull('revoked_at')->sole();
        $this->assertSame(hash('sha256', $token), $access->token_hash);
        $columns = Schema::getColumnListing('loyalty_portal_accesses');
        $this->assertNotContains('token', $columns);
        $this->assertNotContains('qr', $columns);
        $this->assertDatabaseMissing('loyalty_portal_accesses', ['token_hash' => $token]);
    }

    public function test_regenerating_the_access_invalidates_the_previous_link_and_its_printed_qr(): void
    {
        [$company, $branch] = $this->companyContext('QR regenerar');
        $staff = $this->user($company, $branch, true);
        $customer = $this->customer($company, 'CLIENTE-QR-REGEN');
        $service = app(LoyaltyPortalAccessService::class);

        ['url' => $oldUrl] = $this->generateViaService($service, $customer, $company, $staff);
        $printedQr = $service->qrSvg($oldUrl);

        $newUrl = $this->generateOverHttp($staff, $company, $branch, $customer)['url'];

        // El enlace/QR anterior deja de funcionar automáticamente; el nuevo sí funciona.
        $this->get($oldUrl)->assertNotFound();
        $this->get($newUrl)->assertOk();

        // El QR impreso anterior es determinísticamente el del enlace viejo:
        // apunta al enlace ya invalidado, aunque el papel siga mostrándolo.
        $this->assertSame($printedQr, $service->qrSvg($oldUrl));
        $this->assertNotSame($printedQr, $service->qrSvg($newUrl));
    }

    public function test_revoking_the_access_kills_the_target_of_any_previously_printed_qr(): void
    {
        [$company, $branch] = $this->companyContext('QR revocar');
        $staff = $this->user($company, $branch, true);
        $customer = $this->customer($company, 'CLIENTE-QR-REVOCAR');
        $service = app(LoyaltyPortalAccessService::class);

        ['url' => $url] = $this->generateViaService($service, $customer, $company, $staff);

        // Un QR impreso sigue siendo un QR válido como imagen...
        $this->assertStringStartsWith('<svg', $service->qrSvg($url));

        // ...pero al revocar, su destino deja de resolver.
        $this->actingAs($staff)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->patch(route('loyalty.accesses.revoke', $customer))
            ->assertRedirect();
        $this->get($url)->assertNotFound();
    }

    public function test_only_authorized_staff_can_obtain_the_qr(): void
    {
        [$company, $branch] = $this->companyContext('QR permisos');
        $authorized = $this->user($company, $branch, true);
        $unauthorized = $this->user($company, $branch, false);
        $customer = $this->customer($company, 'CLIENTE-QR-PERMISO');

        $session = fn () => ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];

        // Sin sesión: login. Sin permiso: prohibido y sin QR entregado.
        $this->post(route('loyalty.accesses.store'), ['customer_id' => $customer->id])->assertRedirect(route('login'));

        $this->actingAs($unauthorized)->withSession($session())
            ->post(route('loyalty.accesses.store'), ['customer_id' => $customer->id])
            ->assertForbidden();
        $this->assertNull(session('portal_url'));
        $this->assertNull(session('portal_qr'));
        $this->assertSame(0, LoyaltyPortalAccess::count());

        // Con permiso: la respuesta incluye el enlace y su QR.
        $response = $this->actingAs($authorized)->withSession($session())
            ->post(route('loyalty.accesses.store'), ['customer_id' => $customer->id])
            ->assertRedirect();
        $this->assertNotNull($response->getSession()->get('portal_url'));
        $this->assertStringStartsWith('<svg', (string) $response->getSession()->get('portal_qr'));
    }

    public function test_qr_is_company_scoped_like_the_underlying_link(): void
    {
        [$companyA, $branchA] = $this->companyContext('QR empresa A');
        [$companyB, $branchB] = $this->companyContext('QR empresa B');
        $staffA = $this->user($companyA, $branchA, true);
        $staffB = $this->user($companyB, $branchB, true);
        $customerA = $this->customer($companyA, 'CLIENTE-A');
        $customerB = $this->customer($companyB, 'CLIENTE-B');

        $urlB = $this->generateOverHttp($staffB, $companyB, $branchB, $customerB)['url'];

        // El staff de A no puede generar acceso (ni QR) para el cliente de B:
        // 404 y ningún acceso adicional; el único activo sigue siendo el de B.
        $this->actingAs($staffA)->withSession(['active_company_id' => $companyA->id, 'active_branch_id' => $branchA->id])
            ->post(route('loyalty.accesses.store'), ['customer_id' => $customerB->id])
            ->assertNotFound();
        $this->assertSame(1, LoyaltyPortalAccess::query()->where('customer_id', $customerB->id)->whereNull('revoked_at')->count());

        // El QR de B codifica el enlace de B: abre el portal de B, nunca datos de A.
        $this->app['auth']->forgetGuards();
        $this->get($urlB)
            ->assertOk()
            ->assertSee($companyB->trade_name)
            ->assertDontSee($companyA->trade_name);
    }

    private function generateViaService(LoyaltyPortalAccessService $service, Customer $customer, Company $company, User $user): array
    {
        $result = $service->generate($customer, $company, $user);
        $this->assertIsString($result['url']);

        return ['token' => $result['token'], 'url' => $result['url']];
    }

    private function generateOverHttp(User $user, Company $company, Branch $branch, Customer $customer): array
    {
        $response = $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('loyalty.accesses.store'), ['customer_id' => $customer->id]);

        $response->assertRedirect();
        $url = $response->getSession()->get('portal_url');
        $this->assertIsString($url);

        return ['url' => $url];
    }

    private function urlFor(string $token): string
    {
        return route('loyalty.portal.access', ['token' => $token]);
    }

    private function customer(Company $company, string $name, array $extra = []): Customer
    {
        return Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => $name, 'credit_limit' => 0, 'is_active' => true] + $extra);
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
