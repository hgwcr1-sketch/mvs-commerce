<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ElectronicDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FiscalDocumentsPortalTest extends TestCase
{
    use RefreshDatabase;

    private function fiscalContext(): array
    {
        $company = Company::create(['trade_name' => 'FD' . uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P' . uniqid(), 'is_active' => true]);

        return [$company, $branch];
    }

    private function actingUser(Company $company, Branch $branch, array $permissions = ['fiscal.ver']): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'R' . uniqid(), 'is_active' => true]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'Facturación Electrónica', 'is_active' => true],
            );
            $role->permissions()->attach($permission->id);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        $this->actingAs($user)->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);

        return $user;
    }

    private function enableFiscal(Company $company): void
    {
        app(\App\Services\CompanyLicenseService::class)->ensure($company);
        \App\Models\CompanyLicense::query()->where('company_id', $company->id)->update(['fiscal_enabled' => true]);
    }

    private function makeDoc(Company $company, array $attributes = []): ElectronicDocument
    {
        return ElectronicDocument::create(array_merge([
            'company_id' => $company->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => md5(uniqid('fd', true)),
            'status' => 'accepted',
        ], $attributes));
    }

    private function makeSale(Company $company, Branch $branch, array $attributes = []): Sale
    {
        return Sale::create(array_merge([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create()->id,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', uniqid('fd', true)),
            'sale_number' => 'POS-FD-' . uniqid(),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE,
            'sale_condition' => 'cash',
            'status' => Sale::STATUS_COMPLETED,
            'completed_at' => now(),
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 130,
            'total' => 1130,
        ], $attributes));
    }

    public function test_accepted_document_is_visible_with_summary_and_filters(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $this->makeDoc($company, ['consecutivo' => '00100001010000000201', 'status' => 'accepted']);

        $response = $this->get(route('fiscal.documents'));

        $response->assertOk();
        $response->assertSee('00100001010000000201');
        $response->assertSee('data-status="accepted"', false);
        $response->assertSee('Aceptado');
        // Resumen superior
        $response->assertSee('Documentos aceptados');
        $response->assertSee('IVA facturado');
        $response->assertSee('Rechazados / Error');
        $response->assertSee('Pendientes / Procesando');
        // Filtros
        foreach (['from', 'to', 'type', 'status', 'environment', 'branch', 'q'] as $field) {
            $response->assertSee('name="' . $field . '"', false);
        }
        // Sin descargas en Fase 2A
        $response->assertDontSee('Excel del período');
        $response->assertDontSee('ZIP XML del período');
    }

    public function test_rejected_document_is_visible_and_distinguishable(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $this->makeDoc($company, ['consecutivo' => '00100001010000000202', 'status' => 'accepted']);
        $this->makeDoc($company, ['consecutivo' => '00100001010000000203', 'status' => 'rejected', 'last_error_code' => 'FE_REC_001']);

        $response = $this->get(route('fiscal.documents'));

        $response->assertOk();
        $response->assertSee('00100001010000000203');
        // Listado ordenado por id desc: el rechazado (id mayor) va primero.
        $response->assertSeeInOrder(['data-status="rejected"', 'data-status="accepted"'], false);
        $response->assertSee('Rechazado');
        $response->assertSee('FE_REC_001');

        // Scope rechazados: solo rejected/error
        $scoped = $this->get(route('fiscal.documents', ['scope' => 'rechazados']));
        $scoped->assertOk();
        $scoped->assertSee('00100001010000000203');
        $scoped->assertDontSee('00100001010000000202');
    }

    public function test_pending_scope_lists_only_current_fe_te_sales(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);
        [$otherCompany] = $this->fiscalContext();

        // Actual sin electronic_document → pendiente
        $pending = $this->makeSale($company, $branch, ['sale_number' => 'POS-FD-PENDING']);
        // Actual con fallo previo al proveedor → pendiente con razón
        $failed = $this->makeSale($company, $branch, ['sale_number' => 'POS-FD-FAILED']);
        $this->makeDoc($company, [
            'sale_id' => $failed->id,
            'status' => 'error',
            'provider_document_id' => null,
            'last_error_message' => 'Firma inválida',
        ]);
        // Actual con electronic_document emitido → NO pendiente
        $emitted = $this->makeSale($company, $branch, ['sale_number' => 'POS-FD-EMITTED']);
        $this->makeDoc($company, ['sale_id' => $emitted->id, 'status' => 'accepted']);
        // Ticket normal (no FE/TE) → NO pendiente
        $this->makeSale($company, $branch, ['sale_number' => 'POS-FD-TICKET', 'document_type' => Sale::DOCUMENT_TICKET]);
        // Otra empresa → NO aparece
        $this->makeSale($otherCompany, Branch::create(['company_id' => $otherCompany->id, 'name' => 'Otra', 'code' => 'O' . uniqid(), 'is_active' => true]), ['sale_number' => 'POS-FD-OTHER']);

        $response = $this->get(route('fiscal.documents', ['scope' => 'pendientes']));

        $response->assertOk();
        $response->assertSee('POS-FD-PENDING');
        $response->assertSee('POS-FD-FAILED');
        $response->assertSee('Fallo previo al proveedor');
        $response->assertSee('Firma inválida');
        $response->assertDontSee('POS-FD-EMITTED');
        $response->assertDontSee('POS-FD-TICKET');
        $response->assertDontSee('POS-FD-OTHER');
    }

    public function test_historic_sales_are_excluded_by_fiscal_cutoff(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $this->makeSale($company, $branch, [
            'sale_number' => 'POS-FD-HIST',
            'completed_at' => now()->subDays(40),
            'created_at' => now()->subDays(40),
        ]);
        $this->makeSale($company, $branch, ['sale_number' => 'POS-FD-CURRENT']);

        DB::table('company_fiscal_configs')->insertOrIgnore([
            'company_id' => $company->id,
            'fiscal_start_at' => now()->subDays(30)->toDateTimeString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('fiscal.documents', ['scope' => 'pendientes']));

        $response->assertOk();
        $response->assertSee('POS-FD-CURRENT');
        $response->assertDontSee('POS-FD-HIST');
    }

    public function test_filters_by_branch_search_and_type(): void
    {
        [$company, $branchMain] = $this->fiscalContext();
        $branchOther = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Norte', 'code' => 'N' . uniqid(), 'is_active' => true]);
        $this->enableFiscal($company);
        $this->actingUser($company, $branchMain);

        $saleMain = $this->makeSale($company, $branchMain);
        $saleOther = $this->makeSale($company, $branchOther);
        $this->makeDoc($company, ['sale_id' => $saleMain->id, 'consecutivo' => '00100001010000000211', 'document_type' => '01']);
        $this->makeDoc($company, ['sale_id' => $saleOther->id, 'consecutivo' => '00100001010000000212', 'document_type' => '04']);

        // Sucursal
        $this->get(route('fiscal.documents', ['branch' => $branchMain->id]))
            ->assertOk()
            ->assertSee('00100001010000000211')
            ->assertDontSee('00100001010000000212');

        // Búsqueda por consecutivo
        $this->get(route('fiscal.documents', ['q' => '00100001010000000212']))
            ->assertOk()
            ->assertSee('00100001010000000212')
            ->assertDontSee('00100001010000000211');

        // Tipo FE (01) vs TE (04)
        $this->get(route('fiscal.documents', ['type' => '04']))
            ->assertOk()
            ->assertSee('00100001010000000212')
            ->assertDontSee('00100001010000000211');
    }

    public function test_company_isolation_is_never_crossed(): void
    {
        [$companyA, $branchA] = $this->fiscalContext();
        [$companyB] = $this->fiscalContext();
        $this->enableFiscal($companyA);
        $this->actingUser($companyA, $branchA);

        $this->makeDoc($companyA, ['consecutivo' => '00100001010000000221']);
        $foreign = $this->makeDoc($companyB, ['consecutivo' => '00100001010000000222']);

        $this->get(route('fiscal.documents'))
            ->assertOk()
            ->assertSee('00100001010000000221')
            ->assertDontSee('00100001010000000222');

        $this->get(route('fiscal.documents.show', $foreign))->assertNotFound();
        $this->post(route('fiscal.documents.refresh', $foreign))->assertNotFound();
        $this->get(route('fiscal.documents.xml', $foreign))->assertNotFound();
        $this->get(route('fiscal.documents.response', $foreign))->assertNotFound();
    }

    public function test_detail_accepted_shows_clave_consecutivo_and_receipt_link(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $sale = $this->makeSale($company, $branch);
        $doc = $this->makeDoc($company, [
            'sale_id' => $sale->id,
            'clave' => '5060000000000000000100100000101000000000009999999',
            'consecutivo' => '00100001010000000231',
            'provider_document_id' => 'PE-DOC-777',
            'status' => 'accepted',
            'attempt_number' => 2,
        ]);

        $response = $this->get(route('fiscal.documents.show', $doc));

        $response->assertOk();
        $response->assertSee('5060000000000000000100100000101000000000009999999');
        $response->assertSee('00100001010000000231');
        $response->assertSee('PE-DOC-777');
        $response->assertSee('Estado MVS');
        $response->assertSee('Estado proveedor');
        $response->assertSee('Estado Hacienda');
        $response->assertSee('Intentos');
        $response->assertSee($sale->sale_number);
        // PDF fiscal aprobado existente (misma plantilla, sin nuevas vistas)
        $response->assertSee(route('pos.receipt', ['sale' => $sale->id]), false);
        $response->assertSee(route('pos.receipt.pdf', ['sale' => $sale->id, 'format' => 'letter']), false);
        $response->assertSee('Refrescar estado');
    }

    public function test_detail_without_artifacts_shows_no_disponible(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $doc = $this->makeDoc($company, ['clave' => '5060000000000000000100100000101000000000001234567']);

        $response = $this->get(route('fiscal.documents.show', $doc));

        $response->assertOk();
        $response->assertSee('XML enviado: No disponible');
        $response->assertSee('XML firmado: No disponible');
        $response->assertSee('Respuesta Hacienda: No disponible');

        // Las descargas sin artefacto persistido responden "No disponible", nunca inventan.
        $this->get(route('fiscal.documents.xml', $doc))
            ->assertRedirect(route('fiscal.documents.show', $doc))
            ->assertSessionHas('download_notice');
        $this->get(route('fiscal.documents.response', $doc))
            ->assertRedirect(route('fiscal.documents.show', $doc))
            ->assertSessionHas('download_notice');
    }

    public function test_refresh_is_polling_only_and_never_reemits(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);
        Http::fake();

        $pending = $this->makeDoc($company, [
            'status' => 'pending',
            'clave' => null,
            'consecutivo' => '00100001010000000241',
            'attempt_number' => 1,
        ]);
        $accepted = $this->makeDoc($company, [
            'status' => 'accepted',
            'clave' => '5060000000000000000100100000101000000000005678901',
            'consecutivo' => '00100001010000000242',
        ]);

        $before = ElectronicDocument::where('company_id', $company->id)->count();

        $this->post(route('fiscal.documents.refresh', $pending))
            ->assertRedirect(route('fiscal.documents.show', $pending));
        $this->post(route('fiscal.documents.refresh', $accepted))
            ->assertRedirect(route('fiscal.documents.show', $accepted))
            ->assertSessionHas('status');

        // Ni emit() ni polling HTTP: ninguna petición salió de la aplicación.
        $this->assertCount(0, Http::recorded());

        // Nunca crea documentos nuevos ni altera clave/consecutivo/intentos/estado.
        $this->assertSame($before, ElectronicDocument::where('company_id', $company->id)->count());
        $pending->refresh();
        $this->assertNull($pending->clave);
        $this->assertSame('00100001010000000241', $pending->consecutivo);
        $this->assertSame(1, (int) $pending->attempt_number);
        $this->assertSame('pending', $pending->status);
        $accepted->refresh();
        $this->assertSame('5060000000000000000100100000101000000000005678901', $accepted->clave);
        $this->assertSame('00100001010000000242', $accepted->consecutivo);
        $this->assertSame('accepted', $accepted->status);
    }

    public function test_rejected_detail_preserves_error_code_and_message(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $doc = $this->makeDoc($company, [
            'status' => 'rejected',
            'clave' => '5060000000000000000100100000101000000000007654321',
            'last_error_code' => 'FE_REC_103',
            'last_error_message' => 'El receptor no existe en Hacienda.',
        ]);

        $response = $this->get(route('fiscal.documents.show', $doc));

        $response->assertOk();
        $response->assertSee('FE_REC_103');
        $response->assertSee('El receptor no existe en Hacienda.');
        $response->assertSee('5060000000000000000100100000101000000000007654321');
    }
}
