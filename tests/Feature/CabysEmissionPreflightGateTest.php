<?php

namespace Tests\Feature;

use App\DTOs\Fiscal\FiscalCabysEntry;
use App\DTOs\Fiscal\FiscalDocument;
use App\DTOs\Fiscal\FiscalDocumentLine;
use App\DTOs\Fiscal\FiscalDocumentReference;
use App\DTOs\Fiscal\FiscalReferenceException;
use App\Models\CabysCatalogEntry;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalCatalogVersion;
use App\Models\FiscalProfile;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\Unit;
use App\Services\Cabys\CabysCatalogImporter;
use App\Services\CompanyLicenseService;
use App\Services\Fiscal\CabysPreflightGate;
use App\Services\Fiscal\FiscalManager;
use App\Services\Fiscal\FiscalPreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * P1 / FE — CATÁLOGO CABYS OFICIAL EN EL PREFLIGHT DE EMISIÓN.
 *
 * El catálogo oficial del BCCR vive versionado y global
 * (`fiscal_catalog_versions` + `cabys_catalog_entries`) y la emisión FE/TE/NC
 * valida cada línea contra la versión activa:
 *
 *  - el catálogo oficial 2025 tiene 20.506 códigos terminales únicos, sin
 *    duplicados, sin notación científica y sin descripciones vacías;
 *  - `3532306030100` es `Perfumes` (código real del catálogo, no un ejemplo);
 *  - un código bien formado pero ausente del catálogo vigente bloquea FE/TE y
 *    NC: no se infiere, no se corrige y nunca cae a la categoría padre;
 *  - sin versión activa no se bloquea por existencia: la ausencia de catálogo
 *    es un estado operativo conocido, no una invalidez demostrada.
 */
class CabysEmissionPreflightGateTest extends TestCase
{
    use RefreshDatabase;

    private const CODE_PERFUMES = '3532306030100';

    private const CODE_TRIGO = '0111100000100';

    private const CODE_FUERA = '9999999999999';

    private const TOTAL_OFICIAL = 20506;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_catalogo_oficial_2025_tiene_20506_codigos_unicos_y_sin_danos(): void
    {
        $this->catalogoOficial(activate: true);

        $this->assertSame(self::TOTAL_OFICIAL, CabysCatalogEntry::query()->count());
        $this->assertSame(
            self::TOTAL_OFICIAL,
            CabysCatalogEntry::query()->distinct()->count('code'),
            'El catálogo oficial no puede traer códigos duplicados.'
        );

        $this->assertSame(0, CabysCatalogEntry::query()->where('description', '')->count());
        $this->assertSame(0, CabysCatalogEntry::query()->whereNull('description')->count());
        $this->assertSame(0, CabysCatalogEntry::query()
            ->where(function ($query) {
                $query->where('code', 'like', '%e%')
                    ->orWhere('code', 'like', '%,%')
                    ->orWhere('code', 'like', '%.%');
            })
            ->count(), 'Ninguna entrada puede traer notación científica ni separador.');

        $codigos = CabysCatalogEntry::query()->pluck('code');
        $this->assertCount(self::TOTAL_OFICIAL, $codigos);
        $invalidos = array_values(array_filter(
            $codigos->all(),
            static fn ($code): bool => preg_match('/^[0-9]{8,13}$/D', $code) !== 1,
        ));
        $this->assertSame([], array_slice($invalidos, 0, 5), 'Códigos CABYS oficiales con formato inválido.');

        $perfumes = CabysCatalogEntry::query()->where('code', self::CODE_PERFUMES)->firstOrFail();
        $this->assertSame('Perfumes', $perfumes->description);
        $this->assertSame(13.0, $perfumes->taxRatePercent());

        $manager = app(FiscalManager::class);
        $this->assertInstanceOf(FiscalCabysEntry::class, $manager->validateCabys(self::CODE_PERFUMES));
        $this->assertSame('Perfumes', $manager->validateCabys(self::CODE_PERFUMES)->description);
        $this->assertNull($manager->validateCabys(self::CODE_FUERA));
    }

    public function test_preflight_fiscal_bloquea_cabys_fuera_del_catalogo_vigente_y_acepta_el_oficial(): void
    {
        $this->catalogoOficial(activate: true);
        $gate = app(CabysPreflightGate::class);

        $this->assertNull($gate->problem(self::CODE_PERFUMES));
        $this->assertNull($gate->problem(self::CODE_TRIGO));
        $this->assertSame(CabysPreflightGate::REASON_NOT_IN_CATALOG, $gate->problem(self::CODE_FUERA));
        $this->assertSame(CabysPreflightGate::REASON_MISSING, $gate->problem(null));
        $this->assertSame(CabysPreflightGate::REASON_FORMAT, $gate->problem('1,101E+12'));

        $company = $this->company();
        $customer = $this->customer($company);
        $profile = FiscalProfile::query()->where('tax_code', '01')->where('tax_rate_code', '08')->firstOrFail();
        $linea = fn (string $cabys): array => [
            ['product' => $this->product($company, $cabys), 'fiscalProfile' => $profile],
        ];

        app(FiscalPreflightService::class)->validate(
            Sale::DOCUMENT_ELECTRONIC_INVOICE, $company, $customer, $linea(self::CODE_PERFUMES),
        );

        try {
            app(FiscalPreflightService::class)->validate(
                Sale::DOCUMENT_ELECTRONIC_TICKET, $company, $customer, $linea(self::CODE_FUERA),
            );
            $this->fail('Un CABYS fuera del catálogo vigente debió bloquear el preflight.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.cabys_code', $exception->errors());
            $mensaje = $exception->errors()['items.0.cabys_code'][0];
            $this->assertStringContainsString(self::CODE_FUERA, $mensaje);
            $this->assertStringContainsString('no existe en el catálogo vigente', $mensaje);
        }

        Http::assertNothingSent();
    }

    public function test_sin_catalogo_activo_el_preflight_no_afirma_invalidez(): void
    {
        $this->catalogoOficial(activate: false);
        $gate = app(CabysPreflightGate::class);

        $this->assertNull($gate->activeVersionLabel());
        $this->assertNull($gate->problem(self::CODE_PERFUMES));
        $this->assertNull($gate->problem(self::CODE_FUERA));
        $this->assertSame(CabysPreflightGate::REASON_FORMAT, $gate->problem('ABC'));

        $gate->assertInCatalog([self::CODE_FUERA]);

        Http::assertNothingSent();
    }

    public function test_nc_valida_cabys_contra_el_catalogo_vigente_sin_corregir_ni_degradar(): void
    {
        $this->catalogoOficial(activate: true);
        $company = $this->company();
        $documento = $this->notaCredito($company, self::CODE_PERFUMES);

        $documento->validate();

        try {
            app(CabysPreflightGate::class)->assertInCatalog([self::CODE_FUERA]);
            $this->fail('Un CABYS fuera del catálogo vigente debió bloquear la NC.');
        } catch (FiscalReferenceException $exception) {
            $this->assertSame('unknown_cabys', $exception->reason);
            $this->assertStringContainsString(self::CODE_FUERA, $exception->getMessage());
        }

        $this->assertNull(
            CabysCatalogEntry::query()->where('code', self::CODE_FUERA)->first(),
            'Un CABYS inválido nunca se crea por inferencia ni se degrada a la categoría padre.'
        );
        $this->assertSame('Perfumes', $documento->lines[0]->description);
    }

    public function test_nc_y_fe_bloquean_en_el_manager_antes_de_llegar_al_proveedor(): void
    {
        $this->catalogoOficial(activate: true);
        config(['fiscal.provider' => 'fake-gate', 'fiscal.providers.fake-gate' => GateFakeProvider::class]);
        GateFakeProvider::reset();

        $company = $this->company();
        $sale = $this->completedSale($company);
        $original = \App\Models\ElectronicDocument::create([
            'company_id' => $company->id, 'sale_id' => $sale->id, 'provider' => 'fake-gate',
            'document_type' => '01', 'environment' => 'sandbox',
            'idempotency_key' => md5("{$company->id}-{$sale->id}-01"), 'status' => 'accepted',
            'clave' => str_repeat('5', 50), 'consecutivo' => 'C-ORIG',
        ]);

        $this->expectException(FiscalReferenceException::class);
        try {
            app(FiscalManager::class)->emit(new \App\DTOs\Fiscal\FiscalEmissionRequest(
                $sale, $company, $sale->customer, [], null,
                $this->notaCredito($company, self::CODE_FUERA, $original),
            ));
        } finally {
            $this->assertSame(0, GateFakeProvider::calls(), 'El gate debe bloquear ANTES de invocar al proveedor.');
            Http::assertNothingSent();
        }
    }

    private function notaCredito(Company $company, string $cabys, ?\App\Models\ElectronicDocument $original = null): FiscalDocument
    {
        return new FiscalDocument(
            (int) $company->id, '03', 'return', 'NC-1',
            ['identification_type' => '01', 'identification_number' => '109880401', 'name' => 'Receptor'],
            'CRC', '1',
            [new FiscalDocumentLine(
                $cabys, 'Perfumes', '1.0000', '1000.0000', '0.0000', '1000.0000',
                [['codigo' => '01', 'codigoTarifa' => '08', 'tarifa' => 13]], '130.0000', '1130.0000',
            )],
            ['subtotal' => '1000.0000', 'discount_total' => '0.0000', 'tax_total' => '130.0000', 'total' => '1130.0000'],
            new FiscalDocumentReference(
                $original?->id ?? 1, '01', $original?->clave ?? 'K-ORIG', $original?->consecutivo,
                null, '01', 'Devolución', 'fake-gate',
            ),
        );
    }

    private function completedSale(Company $company): Sale
    {
        $branch = \App\Models\Branch::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'P-'.$company->id],
            ['name' => 'Principal', 'is_active' => true],
        );
        $user = \App\Models\User::factory()->create();
        $customer = Customer::create([
            'company_id' => $company->id, 'name' => 'Comprador', 'customer_type' => 'individual',
            'identification_type' => '01', 'identification' => '109880401', 'is_active' => true,
        ]);

        return Sale::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id,
            'customer_id' => $customer->id,
            'sale_number' => 'POS-'.strtoupper(\Illuminate\Support\Str::random(10)),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_INVOICE, 'sale_condition' => 'cash',
            'status' => Sale::STATUS_COMPLETED, 'currency_code' => 'CRC', 'exchange_rate' => 1,
            'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 130, 'total' => 1130,
            'paid_total' => 1130, 'balance_due' => 0, 'completed_at' => now(),
        ]);
    }

    private function catalogoOficial(bool $activate): FiscalCatalogVersion
    {
        $resultado = app(CabysCatalogImporter::class)->import(
            database_path('catalogs/Cabys_BCCR_2025.tsv'),
            'BCCR-2025',
            $activate,
            hash_file('sha256', database_path('catalogs/Cabys_BCCR_2025.xlsx')),
        );

        $this->assertTrue($resultado['ok'], $resultado['message']);
        $this->assertSame(self::TOTAL_OFICIAL, $resultado['imported']);

        return FiscalCatalogVersion::query()
            ->ofKind(FiscalCatalogVersion::KIND_CABYS)
            ->where('source_version', 'BCCR-2025')
            ->firstOrFail();
    }

    private function company(): Company
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(),
            'legal_name' => 'Empresa Fiscal S.A.',
            'identification_number' => '3101000001',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        app(CompanyLicenseService::class)->ensure($company);
        $company->license()->update(['fiscal_enabled' => true]);

        return $company;
    }

    private function customer(Company $company): Customer
    {
        return Customer::create([
            'company_id' => $company->id,
            'name' => 'Cliente',
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '109880401',
            'is_active' => true,
        ]);
    }

    private function product(Company $company, string $cabys): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create([
            'company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix,
            'allows_decimals' => false, 'is_active' => true,
        ]);

        return Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Perfume '.$suffix,
            'internal_code' => 'P-'.$suffix,
            'cost' => 500,
            'sale_price' => 1000,
            'cabys_code' => $cabys,
            'track_inventory' => false,
            'is_active' => true,
        ]);
    }
}

class GateFakeProvider implements \App\Contracts\Fiscal\FiscalProviderInterface
{
    private static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }

    public static function calls(): int
    {
        return self::$calls;
    }

    public function providerCode(): string
    {
        return 'fake-gate';
    }

    public function emit(\App\DTOs\Fiscal\FiscalEmissionRequest $request): \App\DTOs\Fiscal\FiscalEmissionResult
    {
        self::$calls++;

        return new \App\DTOs\Fiscal\FiscalEmissionResult(state: 'error', final: true);
    }

    public function fetchStatus(\App\Models\ElectronicDocument $document): \App\DTOs\Fiscal\FiscalDocumentStatus
    {
        return new \App\DTOs\Fiscal\FiscalDocumentStatus(state: 'error', final: true);
    }
}
