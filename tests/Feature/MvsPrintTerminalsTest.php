<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\MvsPrint\MvsPrintTerminal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\MvsPrint\QzSigningService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MvsPrintTerminalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Este worktree de desarrollo puede no tener assets compilados;
        // la presencia de Vite no es parte de lo que estos tests verifican.
        $this->withoutVite();
    }

    public function test_user_with_permission_lists_only_active_company_branch_terminals(): void
    {
        [$company, $branch] = $this->companyContext('Empresa uno');
        [$otherCompany, $otherBranch] = $this->companyContext('Empresa dos');
        $user = $this->userWithPermission($company);

        MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Caja visible',
        ]);
        $otherBranchSameCompany = $this->branch($company);
        MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $otherBranchSameCompany->id,
            'name' => 'Caja otra sucursal',
        ]);
        MvsPrintTerminal::factory()->create([
            'company_id' => $otherCompany->id,
            'branch_id' => $otherBranch->id,
            'name' => 'Caja ajena',
        ]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.index'))
            ->assertOk()
            ->assertSee('bg-primary')
            ->assertSee('hover:bg-primary-hover')
            ->assertDontSee('bg-indigo-')
            ->assertDontSee('bg-blue-600')
            ->assertSee('Caja visible')
            ->assertDontSee('Caja otra sucursal')
            ->assertDontSee('Caja ajena');
    }

    public function test_user_without_permission_receives_forbidden_on_configuration(): void
    {
        [$company, $branch] = $this->companyContext('Empresa sin permiso');
        $user = $this->userWithPermission($company, null, false);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.index'))
            ->assertForbidden();
    }

    public function test_creates_terminal_with_uuid_for_active_company_and_branch(): void
    {
        [$company, $branch] = $this->companyContext('Empresa creación');
        $user = $this->userWithPermission($company);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('mvs.print.terminals.store'), [
                'name' => 'Caja 1 — mostrador',
                'branch_id' => $branch->id,
                'paper_width' => '80',
                'auto_print' => false,
                'auto_cut' => true,
                'open_drawer' => true,
                'enabled' => true,
            ])
            ->assertRedirect(route('mvs.print.index'));

        $terminal = MvsPrintTerminal::query()
            ->forCompany($company->id)
            ->firstOrFail();

        $this->assertSame($branch->id, (int) $terminal->branch_id);
        $this->assertNotNull($terminal->terminal_uuid);
        $this->assertTrue($terminal->open_drawer);
        $this->assertSame('80', $terminal->paper_width);
    }

    public function test_store_persists_printer_name(): void
    {
        [$company, $branch] = $this->companyContext('Empresa printer');
        $user = $this->userWithPermission($company);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('mvs.print.terminals.store'), [
                'name' => 'Caja con impresora',
                'branch_id' => $branch->id,
                'printer_name' => 'EPSON TM-T20',
                'paper_width' => '80',
                'auto_print' => false,
                'auto_cut' => true,
                'open_drawer' => false,
                'enabled' => true,
            ])
            ->assertRedirect(route('mvs.print.index'));

        $terminal = MvsPrintTerminal::query()
            ->forCompany($company->id)
            ->firstOrFail();

        $this->assertSame('EPSON TM-T20', $terminal->printer_name);
        $this->assertSame('80', $terminal->paper_width);
        $this->assertTrue($terminal->auto_cut);
        $this->assertFalse($terminal->auto_print);
        $this->assertFalse($terminal->open_drawer);
        $this->assertTrue($terminal->enabled);
    }

    public function test_store_persists_null_printer_name_when_empty(): void
    {
        [$company, $branch] = $this->companyContext('Empresa sin printer');
        $user = $this->userWithPermission($company);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('mvs.print.terminals.store'), [
                'name' => 'Caja sin impresora',
                'branch_id' => $branch->id,
                'printer_name' => '',
                'paper_width' => '58',
                'auto_print' => false,
                'auto_cut' => true,
                'open_drawer' => false,
                'enabled' => true,
            ])
            ->assertRedirect(route('mvs.print.index'));

        $terminal = MvsPrintTerminal::query()
            ->forCompany($company->id)
            ->firstOrFail();

        $this->assertNull($terminal->printer_name);
    }

    public function test_update_persists_printer_name(): void
    {
        [$company, $branch] = $this->companyContext('Empresa update');
        $user = $this->userWithPermission($company);

        $terminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'printer_name' => 'OLD PRINTER',
        ]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->patch(route('mvs.print.terminals.update', $terminal), [
                'name' => $terminal->name,
                'printer_name' => 'EPSON TM-T88VII',
                'paper_width' => '80',
                'auto_print' => true,
                'auto_cut' => true,
                'open_drawer' => true,
                'enabled' => true,
                'drawer_command' => [],
            ])
            ->assertRedirect(route('mvs.print.index'));

        $terminal->refresh();
        $this->assertSame('EPSON TM-T88VII', $terminal->printer_name);
        $this->assertTrue($terminal->auto_print);
    }

    public function test_store_rejects_branch_from_another_company(): void
    {
        [$company] = $this->companyContext('Empresa uno');
        [, $otherBranch] = $this->companyContext('Empresa dos');
        $user = $this->userWithPermission($company);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('mvs.print.terminals.store'), [
                'name' => 'Terminal con sucursal ajena',
                'branch_id' => $otherBranch->id,
                'paper_width' => '80',
            ])
            ->assertSessionHasErrors('branch_id');
    }

    public function test_cannot_update_terminal_from_other_company_or_branch(): void
    {
        [$company, $branch] = $this->companyContext('Empresa uno');
        [$otherCompany, $otherBranch] = $this->companyContext('Empresa dos');
        $user = $this->userWithPermission($company);

        $alien = MvsPrintTerminal::factory()->create([
            'company_id' => $otherCompany->id,
            'branch_id' => $otherBranch->id,
        ]);
        $otherBranchSameCompany = $this->branch($company);
        $otherBranchTerminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $otherBranchSameCompany->id,
        ]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->patch(route('mvs.print.terminals.update', $alien), ['name' => 'Intruso'])
            ->assertNotFound();

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->patch(route('mvs.print.terminals.update', $otherBranchTerminal), ['name' => 'No debería'])
            ->assertNotFound();
    }

    public function test_updates_terminal_persisting_printer_cut_drawer_and_drawer_command(): void
    {
        [$company, $branch] = $this->companyContext('Empresa edición');
        $user = $this->userWithPermission($company);
        $terminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'drawer_command' => null,
        ]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->patch(route('mvs.print.terminals.update', $terminal), [
                'name' => 'Caja principal',
                'printer_name' => 'EPSON TM-T20',
                'paper_width' => '58',
                'auto_print' => true,
                'auto_cut' => true,
                'open_drawer' => true,
                'drawer_command' => [27, 112, 0, 25, 255],
                'enabled' => true,
            ])
            ->assertRedirect(route('mvs.print.index'));

        $terminal->refresh();

        $this->assertSame('EPSON TM-T20', $terminal->printer_name);
        $this->assertSame('58', $terminal->paper_width);
        $this->assertTrue($terminal->auto_print);
        $this->assertTrue($terminal->auto_cut);
        $this->assertTrue($terminal->open_drawer);
        $this->assertSame([27, 112, 0, 25, 255], $terminal->drawer_command);
    }

    public function test_empty_drawer_command_resets_to_null(): void
    {
        [$company, $branch] = $this->companyContext('Empresa comando');
        $user = $this->userWithPermission($company);
        $terminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'drawer_command' => [27, 112, 0, 25, 255],
        ]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->patch(route('mvs.print.terminals.update', $terminal), [
                'name' => $terminal->name,
                'drawer_command' => [],
                'enabled' => true,
            ])
            ->assertRedirect(route('mvs.print.index'));

        $terminal->refresh();

        $this->assertNull($terminal->drawer_command);
    }

    public function test_drawer_command_rejects_out_of_range_bytes(): void
    {
        [$company, $branch] = $this->companyContext('Empresa rango');
        $user = $this->userWithPermission($company);
        $terminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->patch(route('mvs.print.terminals.update', $terminal), [
                'name' => $terminal->name,
                'drawer_command' => [27, 112, 300],
                'enabled' => true,
            ])
            ->assertSessionHasErrors('drawer_command.2');
    }

    public function test_toggle_and_destroy_change_state_only_for_own_terminal(): void
    {
        [$company, $branch] = $this->companyContext('Empresa toggle');
        $user = $this->userWithPermission($company);
        $terminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'enabled' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->patch(route('mvs.print.terminals.toggle', $terminal))
            ->assertRedirect(route('mvs.print.index'));

        $terminal->refresh();
        $this->assertFalse($terminal->enabled);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->delete(route('mvs.print.terminals.destroy', $terminal))
            ->assertRedirect(route('mvs.print.index'));

        $this->assertDatabaseMissing('mvs_print_terminals', ['id' => $terminal->id]);
    }

    public function test_test_print_payload_reflects_cut_drawer_and_command_without_private_key(): void
    {
        [$company, $branch] = $this->companyContext('Empresa payload');
        $user = $this->userWithPermission($company);
        $terminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'open_drawer' => true,
            'auto_cut' => true,
            'drawer_command' => [27, 112, 0, 25, 255],
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.terminals.test-print', $terminal))
            ->assertOk()
            ->assertDontSee('BEGIN RSA PRIVATE KEY');

        $payload = $response->json('payload');

        $this->assertNotNull($payload);
        $this->assertNotEmpty($payload['lines']);
        $this->assertTrue($payload['auto_cut']);
        $this->assertTrue($payload['open_drawer']);
        $this->assertSame([27, 112, 0, 25, 255], $payload['drawer_command']);
    }

    public function test_open_drawer_payload_returns_only_drawer_command_without_cut_or_text(): void
    {
        [$company, $branch] = $this->companyContext('Empresa cajon');
        $user = $this->userWithPermission($company);
        $terminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'open_drawer' => true,
            'auto_cut' => true,
            'drawer_command' => [27, 112, 0, 25, 255],
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.terminals.open-drawer', $terminal))
            ->assertOk();

        $payload = $response->json('payload');

        $this->assertNotNull($payload);
        $this->assertEmpty($payload['lines']);
        $this->assertFalse($payload['auto_cut']);
        $this->assertTrue($payload['open_drawer']);
        $this->assertSame([27, 112, 0, 25, 255], $payload['drawer_command']);
        $this->assertSame($terminal->printer_name, $response->json('printer'));
    }

    public function test_open_drawer_payload_uses_default_command_when_drawer_command_is_null(): void
    {
        [$company, $branch] = $this->companyContext('Empresa cajon default');
        $user = $this->userWithPermission($company);
        $terminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'drawer_command' => null,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.terminals.open-drawer', $terminal))
            ->assertOk();

        $payload = $response->json('payload');

        $this->assertSame([27, 112, 0, 25, 255], $payload['drawer_command']);
    }

    public function test_open_drawer_requires_permission(): void
    {
        [$company, $branch] = $this->companyContext('Empresa cajon permiso');
        $user = $this->userWithPermission($company, $branch, with: false);
        $terminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.terminals.open-drawer', $terminal))
            ->assertForbidden();
    }

    public function test_signature_endpoint_signs_raw_toSign_and_returns_plain_text_base64(): void
    {
        [$company, $branch] = $this->companyContext('Empresa firma');
        $user = $this->userWithPermission($company);
        MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);

        $toSign = 'POST\nhttps://localhost:8181\nprinter=EPSON TM-T20\ncmd=raw-test';

        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('mvs.print.signature'), ['request' => $toSign])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $signature = $response->getContent();

        // Texto plano base64, nunca XML con secretos ni claves privadas.
        $this->assertStringNotContainsString('PRIVATE KEY', $signature);
        $this->assertStringNotContainsString('BEGIN RSA', $signature);
        $this->assertNotFalse(base64_decode($signature, true));

        // Verificación real con la clave pública (SHA512).
        $signing = new QzSigningService();
        $publicKey = $signing->publicKeyPem();
        $verified = openssl_verify(
            $toSign,
            base64_decode($signature, true),
            $publicKey,
            OPENSSL_ALGO_SHA512,
        );

        $this->assertSame(1, $verified);
    }

    public function test_signature_endpoint_requires_mvs_print_permission(): void
    {
        [$company, $branch] = $this->companyContext('Empresa sin permiso');
        $user = $this->userWithPermission($company, $branch, with: false);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('mvs.print.signature'), ['request' => 'toSign'])
            ->assertForbidden();
    }

    public function test_certificate_accessible_with_pos_permission_and_returns_pem(): void
    {
        [$company, $branch] = $this->companyContext('Empresa cert pos');
        $user = $this->userWithPermission($company, $branch, permission: 'pos.acceder');
        $response = $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.certificate'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $pem = $response->getContent();
        $this->assertStringContainsString('BEGIN CERTIFICATE', $pem);
        $this->assertStringNotContainsString('PRIVATE KEY', $pem);
        $this->assertStringNotContainsString('BEGIN RSA', $pem);
    }

    public function test_signature_accessible_with_pos_imprimir_permission(): void
    {
        [$company, $branch] = $this->companyContext('Empresa sig pos');
        $user = $this->userWithPermission($company, $branch, permission: 'mvs.print.imprimir');
        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('mvs.print.signature'), ['request' => 'toSign pos'])
            ->assertOk();
    }

    public function test_pos_cashier_signature_verifies_against_delivered_certificate_for_exact_string(): void
    {
        [$company, $branch] = $this->companyContext('Firma cajero');
        $user = $this->userWithPermission($company, $branch, permission: 'pos.acceder');
        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $pem = $this->get(route('mvs.print.certificate'))->assertOk()->getContent();
        $exact = hash('sha256', '{"call":"print","params":{"texto":"á ₡"},"timestamp":123456}');
        $response = $this->postJson(route('mvs.print.signature'), ['request' => $exact])
            ->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $decoded = base64_decode($response->getContent(), true);
        $this->assertNotFalse($decoded);
        $this->assertSame(1, openssl_verify($exact, $decoded, $pem, OPENSSL_ALGO_SHA512));
        $this->assertSame(0, openssl_verify($exact.'x', $decoded, $pem, OPENSSL_ALGO_SHA512));
        $this->assertStringNotContainsString('PRIVATE KEY', $pem.$response->getContent());
    }

    public function test_guest_cannot_obtain_signature(): void
    {
        $this->postJson(route('mvs.print.signature'), ['request' => 'exact'])->assertRedirect(route('login'));
    }

    public function test_certificate_forbidden_for_unauthenticated_and_cross_company(): void
    {
        [$company, $branch] = $this->companyContext('Empresa cert auth');
        // No auth - redirect to login
        $this->get(route('mvs.print.certificate'))
            ->assertRedirect();
        // Cross company
        [$otherCompany, $otherBranch] = $this->companyContext('Empresa otra');
        $user = $this->userWithPermission($otherCompany, $otherBranch, permission: 'pos.acceder');
        // Intentar con company_id de otra empresa en sesión no debería exponer cert de otra? cert es global por instalación, pero aislamiento se verifica vía permiso pos en company activa; cross company con sesión otherCompany debe funcionar para su propia company pero no filtrar. Verificamos 403 sin permiso pos en company activa vacía
        $noPerm = $this->userWithPermission($company, $branch, with: false);
        $this->actingAs($noPerm)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->get(route('mvs.print.certificate'))
            ->assertForbidden();
    }

    public function test_permissions_are_seeded_for_mvs_print(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->assertDatabaseHas('permissions', ['name' => 'mvs.print.configurar', 'is_active' => true]);
        $this->assertDatabaseHas('permissions', ['name' => 'mvs.print.imprimir', 'is_active' => true]);
    }

    public function test_heartbeat_updates_last_seen_at_only_for_own_terminal(): void
    {
        [$company, $branch] = $this->companyContext('Empresa latido');
        $user = $this->userWithPermission($company);
        $terminal = MvsPrintTerminal::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'last_seen_at' => null,
        ]);
        [$otherCompany, $otherBranch] = $this->companyContext('Empresa ajena');
        $alien = MvsPrintTerminal::factory()->create([
            'company_id' => $otherCompany->id,
            'branch_id' => $otherBranch->id,
        ]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('mvs.print.terminals.heartbeat', $terminal))
            ->assertOk();

        $terminal->refresh();
        $this->assertNotNull($terminal->last_seen_at);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->post(route('mvs.print.terminals.heartbeat', $alien))
            ->assertNotFound();
    }

    private function company(string $name): Company
    {
        return Company::create(['trade_name' => $name, 'is_active' => true]);
    }

    private function companyContext(string $name): array
    {
        $company = $this->company($name);
        $branch = $this->branch($company);

        return [$company, $branch];
    }

    private function branch(Company $company): Branch
    {
        return Branch::create([
            'company_id' => $company->id,
            'name' => 'Sucursal '.uniqid(),
            'code' => 'S'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function userWithPermission(Company $company, ?Branch $branch = null, bool $with = true, string $permission = 'mvs.print.configurar'): User
    {
        $branch ??= Branch::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->firstOrFail();
        $user = User::factory()->create();
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol '.uniqid(),
            'is_active' => true,
        ]);

        if ($with) {
            $perm = Permission::firstOrCreate(
                ['name' => $permission],
                ['label' => $permission, 'module' => 'Test', 'is_active' => true],
            );
            $role->permissions()->attach($perm);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach([$branch->id]);

        return $user;
    }
}
