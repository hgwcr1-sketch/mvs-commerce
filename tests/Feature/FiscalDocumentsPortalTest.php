<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\FiscalDocumentCustody;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Support\FiscalCustodyArtifacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class FiscalDocumentsPortalTest extends TestCase
{
    use RefreshDatabase;

    private function fiscalContext(): array
    {
        $company = Company::create(['trade_name' => 'FD'.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);

        return [$company, $branch];
    }

    private function actingUser(Company $company, Branch $branch, array $permissions = ['fiscal.ver']): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);

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
        app(CompanyLicenseService::class)->ensure($company);
        CompanyLicense::query()->where('company_id', $company->id)->update(['fiscal_enabled' => true]);
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
            'sale_number' => 'POS-FD-'.uniqid(),
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
            $response->assertSee('name="'.$field.'"', false);
        }
        // Descargas del período visibles SOLO en Documentos (Fase 2C)
        $response->assertSee('Descargar Excel');
        $response->assertSee('Descargar XML (ZIP)');
        $response->assertSee(route('fiscal.documents.excel', []), false);
        $response->assertSee(route('fiscal.documents.zip', []), false);
    }

    public function test_download_buttons_are_only_in_documents_view(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        foreach (['fiscal.index', 'fiscal.history'] as $routeName) {
            $this->get(route($routeName))
                ->assertOk()
                ->assertDontSee('Descargar Excel')
                ->assertDontSee('Descargar XML (ZIP)');
        }

        $this->get(route('fiscal.documents'))
            ->assertOk()
            ->assertSee('Descargar Excel')
            ->assertSee('Descargar XML (ZIP)');
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
        $this->makeSale($otherCompany, Branch::create(['company_id' => $otherCompany->id, 'name' => 'Otra', 'code' => 'O'.uniqid(), 'is_active' => true]), ['sale_number' => 'POS-FD-OTHER']);

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

    public function test_pending_sale_with_unconvertible_unit_shows_human_reason(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        DB::table('company_fiscal_configs')->insert([
            'company_id' => $company->id,
            'provider' => 'facturaencr',
            'environment' => 'sandbox',
            'provider_api_key' => encrypt('efk_TESTKEY', false),
            'provider_api_secret' => encrypt('efk_TESTSECRET', false),
            'auto_emit_enabled' => true,
            'fiscal_start_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '109880401',
            'name' => 'Cliente',
        ]);

        $sale = $this->makeSale($company, $branch, ['customer_id' => $customer->id]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => 'P01',
            'cabys_code' => '3532306030100',
            'description' => 'Producto',
            'unit_code' => 'PIEZA',
            'quantity' => 1,
            'unit_price' => 1000,
            'gross_total' => 1000,
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_rate' => 13,
            'tax_total' => 130,
            'total' => 1130,
            'unit_cost' => 500,
        ]);

        $response = $this->get(route('fiscal.documents', ['scope' => 'pendientes']));

        $response->assertOk();
        $response->assertSee('Preflight: unidad');
        $response->assertSee('no convertible a unidad fiscal');
        $response->assertDontSee('Aún no procesado por el flujo de emisión.');
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
        $branchOther = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Norte', 'code' => 'N'.uniqid(), 'is_active' => true]);
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

    public function test_period_excel_exports_only_current_company_documents_with_required_columns(): void
    {
        [$company, $branch] = $this->fiscalContext();
        [$otherCompany] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'name' => 'Cliente Export',
            'identification_type' => '01',
            'identification' => '3101000001',
            'is_active' => true,
        ]);
        $sale = $this->makeSale($company, $branch, ['customer_id' => $customer->id]);

        $this->makeDoc($company, [
            'sale_id' => $sale->id,
            'consecutivo' => '00100001010000000301',
            'clave' => '506000000000000000010010000010100000000000301301',
            'provider_document_id' => 'PE-DOC-901',
            'status' => 'accepted',
        ]);
        $this->makeDoc($otherCompany, [
            'consecutivo' => '00100001010000000302',
            'clave' => '506000000000000000010010000010100000000000302302',
            'provider_document_id' => 'PE-DOC-AJENO',
        ]);

        $response = $this->get(route('fiscal.documents.excel'));
        $response->assertOk();

        $rows = $this->excelRows($response);

        $this->assertSame([
            'Fecha/hora', 'Tipo', 'Consecutivo', 'Clave', 'Receptor', 'Identificación',
            'Sucursal', 'Ambiente', 'Estado', 'Total', 'IVA', 'Provider document ID',
        ], $rows[0]);
        $this->assertCount(2, $rows);

        $row = $rows[1];
        $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/', (string) $row[0]);
        $this->assertSame('FE - Factura electrónica', $row[1]);
        $this->assertSame('00100001010000000301', $row[2]);
        $this->assertSame('506000000000000000010010000010100000000000301301', $row[3]);
        $this->assertSame('Cliente Export', $row[4]);
        $this->assertSame('3101000001', $row[5]);
        $this->assertSame('Principal', $row[6]);
        $this->assertSame('Pruebas', $row[7]);
        $this->assertSame('Aceptado', $row[8]);
        $this->assertEquals(1130, $row[9]);
        $this->assertEquals(130, $row[10]);
        $this->assertSame('PE-DOC-901', $row[11]);

        $flat = implode('|', array_map('strval', $row));
        $this->assertStringNotContainsString('PE-DOC-AJENO', $flat);
        $this->assertStringNotContainsString('00100001010000000302', $flat);
    }

    public function test_period_excel_reflects_active_filters_and_never_invents_data(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $branchOther = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Norte', 'code' => 'N'.uniqid(), 'is_active' => true]);
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $saleMain = $this->makeSale($company, $branch);
        $saleOther = $this->makeSale($company, $branchOther);
        $this->makeDoc($company, ['sale_id' => $saleMain->id, 'consecutivo' => '00100001010000000321', 'document_type' => '01', 'status' => 'accepted']);
        $this->makeDoc($company, ['sale_id' => $saleOther->id, 'consecutivo' => '00100001010000000322', 'document_type' => '04', 'status' => 'rejected']);
        // Sin venta relacionada: los campos dependientes quedan vacíos, nunca inventados.
        $this->makeDoc($company, ['consecutivo' => '00100001010000000323', 'document_type' => '03', 'status' => 'accepted']);

        $rows = $this->excelRows($this->get(route('fiscal.documents.excel', ['type' => '04']))->assertOk());
        $this->assertCount(2, $rows);
        $this->assertSame('00100001010000000322', $rows[1][2]);

        $rows = $this->excelRows($this->get(route('fiscal.documents.excel', ['branch' => $branchOther->id]))->assertOk());
        $this->assertCount(2, $rows);
        $this->assertSame('00100001010000000322', $rows[1][2]);

        $rows = $this->excelRows($this->get(route('fiscal.documents.excel', ['status' => 'rejected']))->assertOk());
        $this->assertCount(2, $rows);
        $this->assertSame('00100001010000000322', $rows[1][2]);

        $rows = $this->excelRows($this->get(route('fiscal.documents.excel', ['q' => '00100001010000000321']))->assertOk());
        $this->assertCount(2, $rows);
        $this->assertSame('00100001010000000321', $rows[1][2]);

        $rows = $this->excelRows($this->get(route('fiscal.documents.excel', ['q' => '00100001010000000323']))->assertOk());
        $this->assertSame('', (string) $rows[1][4]);
        $this->assertSame('', (string) $rows[1][6]);
        $this->assertSame('', (string) $rows[1][9]);
        $this->assertSame('', (string) $rows[1][10]);

        // Período: documento anterior a la fecha mínima queda fuera.
        $old = $this->makeDoc($company, ['consecutivo' => '00100001010000000324']);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $rows = $this->excelRows($this->get(route('fiscal.documents.excel', [
            'from' => now()->subDays(10)->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk());
        $this->assertNotContains('00100001010000000324', array_column($rows, 2));
    }

    public function test_period_zip_includes_only_really_persisted_xml_and_counts_excluded_documents(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $clave = '506000000000000000010010000010100000000000311311';
        $withCustody = $this->makeDoc($company, [
            'consecutivo' => '00100001010000000311',
            'clave' => $clave,
            'provider_document_id' => 'PE-DOC-ZIP',
        ]);
        FiscalDocumentCustody::create([
            'electronic_document_id' => $withCustody->id,
            'payload' => ['clave' => $clave, 'xml' => '<Factura>ENVIADA</Factura>'],
            'response' => ['status' => 'accepted', 'xml' => '<Factura>FIRMADA</Factura>'],
        ]);
        $withoutCustody = $this->makeDoc($company, [
            'consecutivo' => '00100001010000000312',
            'clave' => '506000000000000000010010000010100000000000312312',
        ]);

        $response = $this->get(route('fiscal.documents.zip'));
        $response->assertOk()->assertHeader('Content-Type', 'application/zip');
        $entries = $this->zipEntries($response);

        $base = 'FE_00100001010000000311_'.$clave;
        $this->assertSame([
            'resumen_descarga.txt',
            $base.'_firmado.xml',
            $base.'_enviado.xml',
            $base.'_respuesta_hacienda.json',
        ], array_keys($entries));
        $this->assertSame('<Factura>ENVIADA</Factura>', $entries[$base.'_enviado.xml']);
        $this->assertSame('<Factura>FIRMADA</Factura>', $entries[$base.'_firmado.xml']);

        // El documento sin custodia NO recibe ningún archivo inventado.
        foreach (array_keys($entries) as $name) {
            $this->assertStringNotContainsString('00100001010000000312', $name);
        }

        // Todos los .xml son contenido XML real persistido.
        foreach ($entries as $name => $content) {
            if (str_ends_with($name, '.xml')) {
                $this->assertStringContainsString('<', $content);
            }
        }

        // Manifiesto: registra y muestra cuántos quedaron fuera por falta de custodia.
        $this->assertArrayHasKey('resumen_descarga.txt', $entries);
        $this->assertStringContainsString('Documentos incluidos con XML persistido: 1', $entries['resumen_descarga.txt']);
        $this->assertStringContainsString('Documentos excluidos por falta de custodia/XML: 1', $entries['resumen_descarga.txt']);
        $this->assertStringContainsString('00100001010000000312', $entries['resumen_descarga.txt']);
        $this->assertSame('1', $response->headers->get('X-MVS-Documentos-Excluidos'));
    }

    public function test_artifact_order_with_three_xml_is_firmado_enviado_and_respuesta_hacienda(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $clave = '50600000000000000001001000001010000000000004141';
        $withCustody = $this->makeDoc($company, [
            'consecutivo' => '00100001010000000411',
            'clave' => $clave,
            'provider_document_id' => 'PE-DOC-ORDEN',
        ]);
        $custody = FiscalDocumentCustody::create([
            'electronic_document_id' => $withCustody->id,
            'payload' => ['clave' => $clave, 'xml' => '<Factura>ENVIADA</Factura>'],
            'xml_signed' => '<Factura>FIRMADA</Factura>',
            'hacienda_response_xml' => '<MensajeHacienda>ACEPTADO</MensajeHacienda>',
        ]);

        $this->assertSame(
            ['_firmado', '_enviado', '_respuesta_hacienda'],
            array_keys(FiscalCustodyArtifacts::xmls($custody))
        );

        $entries = $this->zipEntries($this->get(route('fiscal.documents.zip'))->assertOk());

        $base = 'FE_00100001010000000411_'.$clave;
        $this->assertSame([
            'resumen_descarga.txt',
            $base.'_firmado.xml',
            $base.'_enviado.xml',
            $base.'_respuesta_hacienda.xml',
        ], array_keys($entries));
        $this->assertSame('<Factura>FIRMADA</Factura>', $entries[$base.'_firmado.xml']);
        $this->assertSame('<Factura>ENVIADA</Factura>', $entries[$base.'_enviado.xml']);
        $this->assertSame('<MensajeHacienda>ACEPTADO</MensajeHacienda>', $entries[$base.'_respuesta_hacienda.xml']);
    }

    public function test_period_zip_excludes_other_company_documents_and_uses_safe_file_names(): void
    {
        [$company, $branch] = $this->fiscalContext();
        [$otherCompany] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $own = $this->makeDoc($company, [
            'consecutivo' => '00100001010000000331',
            'clave' => '../../salida 506',
        ]);
        FiscalDocumentCustody::create([
            'electronic_document_id' => $own->id,
            'payload' => ['xml' => '<A>1</A>'],
        ]);

        $foreign = $this->makeDoc($otherCompany, [
            'consecutivo' => '00100001010000000332',
            'clave' => '506000000000000000010010000010100000000000332332',
        ]);
        FiscalDocumentCustody::create([
            'electronic_document_id' => $foreign->id,
            'payload' => ['xml' => '<Ajeno>NO</Ajeno>'],
        ]);

        $entries = $this->zipEntries($this->get(route('fiscal.documents.zip'))->assertOk());

        foreach (array_keys($entries) as $name) {
            $this->assertStringNotContainsString('00100001010000000332', $name);
            $this->assertStringNotContainsString('/', $name);
            $this->assertStringNotContainsString('\\', $name);
            $this->assertStringNotContainsString('..', $name);
            if ($name !== 'resumen_descarga.txt') {
                $this->assertStringStartsWith('FE_', $name);
            }
        }
        foreach ($entries as $content) {
            $this->assertStringNotContainsString('<Ajeno>NO</Ajeno>', (string) $content);
        }

        $this->assertArrayHasKey('FE_00100001010000000331_salida_506_enviado.xml', $entries);
        $this->assertSame('<A>1</A>', $entries['FE_00100001010000000331_salida_506_enviado.xml']);
    }

    public function test_period_zip_without_any_persisted_xml_answers_with_notice_and_no_file(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch);

        $this->makeDoc($company, ['consecutivo' => '00100001010000000341']);

        $response = $this->get(route('fiscal.documents.zip'));

        $response->assertRedirect(route('fiscal.documents', [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertSessionHas('download_notice', fn ($notice) => str_contains((string) $notice, 'No disponible'));

        // El Excel vacío responde igual: nunca fabrica filas.
        $this->get(route('fiscal.documents.excel', ['q' => 'que-no-existe']))
            ->assertRedirect()
            ->assertSessionHas('download_notice');
    }

    public function test_period_downloads_require_fiscal_ver_permission(): void
    {
        [$company, $branch] = $this->fiscalContext();
        $this->enableFiscal($company);
        $this->actingUser($company, $branch, []);

        $this->get(route('fiscal.documents.excel'))->assertForbidden();
        $this->get(route('fiscal.documents.zip'))->assertForbidden();
    }

    private function excelRows($response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'fd-xlsx-');
        file_put_contents($path, $response->streamedContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();
        unlink($path);

        return $rows;
    }

    private function zipEntries($response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'fd-zip-');
        file_put_contents($path, $response->getContent());

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $entries;
    }
}
