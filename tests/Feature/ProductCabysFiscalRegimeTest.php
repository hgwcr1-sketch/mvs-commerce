<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CabysCatalogEntry;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\FiscalCatalogVersion;
use App\Models\FiscalProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCabysAssignment;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Cabys\ProductCabysService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C) CABYS → IMPUESTO según régimen fiscal de la empresa.
 *
 * El régimen fiscal (general | simplified | unknown) vive en la
 * configuración fiscal canónica de cada empresa y decide si la tarifa
 * oficial del CABYS se aplica al impuesto del producto o solo queda
 * registrada como evidencia. Reglas que no pueden romperse:
 *
 *  - `general` o sin configuración fiscal: la tarifa oficial se aplica
 *    cuando no contradice una tarifa manual ni el perfil fiscal;
 *  - `simplified` y `unknown`: la tarifa oficial SOLO se registra, el
 *    impuesto de venta no se toca y el origen manual se conserva;
 *  - el perfil fiscal del producto es la AUTORIDAD: CABYS nunca lo pisa
 *    y `tax_rate` permanece coherente con él;
 *  - códigos de 11 a 13 dígitos; texto libre y códigos más largos siguen
 *    rechazados por validación;
 *  - el régimen de una empresa jamás afecta a otra.
 */
class ProductCabysFiscalRegimeTest extends TestCase
{
    use RefreshDatabase;

    private const CODE_ONE_PERCENT = '0111100000100';

    private const CODE_ELEVEN_DIGITS = '01111000001';

    private ?int $companyId = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_general_regime_applies_official_rate_to_product_without_tax(): void
    {
        [$company] = $this->context();
        $this->activeCatalog();
        $this->regime($company, CompanyFiscalConfig::TAX_REGIME_GENERAL);

        $product = $this->product($company);
        $product->forceFill(['tax_rate' => null, 'tax_rate_source' => 'manual'])->save();

        $proposal = $this->confirm($company, $product, self::CODE_ONE_PERCENT);

        $product->refresh();

        $this->assertSame('applied', $proposal['status']);
        $this->assertEquals(1.0, (float) $product->tax_rate);
        $this->assertSame('cabys_confirmed', $product->tax_rate_source);
        $this->assertEquals(1.0, (float) $product->tax_rate_official_pct);
        $this->assertSame('1%', $product->tax_rate_official_raw);
        $this->assertNull($product->fiscal_profile_id);
        $this->assertNull($product->fiscalProfile);
    }

    public function test_general_regime_resolves_equivalent_profile_for_each_supported_rate(): void
    {
        [$company] = $this->context();
        $version = $this->activeCatalog();
        $this->entry($version, '0111100000400', 'Producto tasa 4', '4%');
        $this->entry($version, '0111100000130', 'Producto tasa 13', '13%');
        $this->regime($company, CompanyFiscalConfig::TAX_REGIME_GENERAL);

        $cases = [
            self::CODE_ONE_PERCENT => 1.0,
            '0111100000200' => 2.0,
            '0111100000400' => 4.0,
            '0111100000130' => 13.0,
        ];

        foreach ($cases as $code => $rate) {
            $product = $this->product($company, ['internal_code' => 'PR-'.uniqid()]);
            $product->forceFill(['tax_rate' => null, 'tax_rate_source' => 'manual'])->save();

            $proposal = $this->confirm($company, $product, $code);

            $product->refresh();

            $this->assertSame('applied', $proposal['status'], 'tasa '.$rate);
            $this->assertEquals($rate, (float) $product->tax_rate, 'tasa '.$rate);
            $this->assertNull($product->fiscal_profile_id, 'tasa '.$rate);
            $this->assertNull($product->fiscalProfile, 'tasa '.$rate);
        }
    }

    public function test_general_regime_confirms_but_keeps_fiscal_profile_authority(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.ver']);
        $this->activeCatalog();
        $this->regime($company, CompanyFiscalConfig::TAX_REGIME_GENERAL);
        $exempt = $this->profile('01', '10');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('productos.store'), $this->payload([
                'fiscal_profile_id' => $exempt->id,
                'cabys_proposed_code' => self::CODE_ONE_PERCENT,
            ]))
            ->assertRedirect(route('productos.index'));

        $product = Product::query()->where('company_id', $company->id)->firstOrFail();

        $this->assertSame($exempt->id, (int) $product->fiscal_profile_id);
        $this->assertSame(0.0, (float) $product->tax_rate);
        $this->assertEquals(1.0, (float) $product->tax_rate_official_pct);
        $this->assertSame('1%', $product->tax_rate_official_raw);
        $this->assertNotSame('cabys_confirmed', $product->tax_rate_source);
        $this->assertSame(self::CODE_ONE_PERCENT, $product->cabys_code);
        $this->assertSame(
            (float) $product->tax_rate,
            (float) $product->fiscalProfile->rate
        );
    }

    public function test_simplified_regime_records_official_rate_without_applying_it(): void
    {
        [$company] = $this->context();
        $this->activeCatalog();
        $this->regime($company, CompanyFiscalConfig::TAX_REGIME_SIMPLIFIED);

        $product = $this->product($company);
        $product->forceFill(['tax_rate' => null, 'tax_rate_source' => 'manual'])->save();

        $proposal = $this->confirm($company, $product, self::CODE_ONE_PERCENT);

        $product->refresh();

        $this->assertSame('recorded', $proposal['status']);
        $this->assertNull($product->tax_rate);
        $this->assertSame('manual', $product->tax_rate_source);
        $this->assertEquals(1.0, (float) $product->tax_rate_official_pct);
        $this->assertSame('1%', $product->tax_rate_official_raw);
        $this->assertSame(self::CODE_ONE_PERCENT, $product->cabys_code);
    }

    public function test_unknown_regime_keeps_manual_tax_and_records_official_rate(): void
    {
        [$company] = $this->context();
        $this->activeCatalog();
        $this->regime($company, CompanyFiscalConfig::TAX_REGIME_UNKNOWN);

        $product = $this->product($company, ['tax_rate' => 13]);
        $product->forceFill(['tax_rate_source' => 'manual'])->save();

        $proposal = $this->confirm($company, $product, self::CODE_ONE_PERCENT);

        $product->refresh();

        $this->assertSame('recorded', $proposal['status']);
        $this->assertEquals(13.0, (float) $product->tax_rate);
        $this->assertSame('manual', $product->tax_rate_source);
        $this->assertEquals(1.0, (float) $product->tax_rate_official_pct);
        $this->assertSame('1%', $product->tax_rate_official_raw);
        $this->assertSame(self::CODE_ONE_PERCENT, $product->cabys_code);
    }

    public function test_absent_fiscal_config_keeps_the_legacy_auto_apply(): void
    {
        [$company] = $this->context();
        $this->activeCatalog();

        $product = $this->product($company);
        $product->forceFill(['tax_rate' => null, 'tax_rate_source' => 'manual'])->save();

        $proposal = $this->confirm($company, $product, self::CODE_ONE_PERCENT);

        $product->refresh();

        $this->assertSame('applied', $proposal['status']);
        $this->assertEquals(1.0, (float) $product->tax_rate);
        $this->assertSame('cabys_confirmed', $product->tax_rate_source);
        $this->assertNull($product->fiscal_profile_id);
    }

    public function test_general_regime_on_edit_keeps_pending_profile_pending(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.editar', 'productos.ver']);
        $this->activeCatalog();
        $this->regime($company, CompanyFiscalConfig::TAX_REGIME_GENERAL);
        $product = $this->product($company, ['tax_rate' => null]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('productos.update', $product), $this->payload([
                'internal_code' => $product->internal_code,
                'cabys_proposed_code' => '0111100000100',
                'tax_rate' => null,
            ]))
            ->assertRedirect(route('productos.index'));

        $product->refresh();
        $this->assertEquals(1.0, (float) $product->tax_rate);
        $this->assertNull($product->fiscal_profile_id);
        $this->assertNull($product->fiscalProfile);
    }

    public function test_eleven_digit_code_is_confirmed_from_the_catalog(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.ver']);
        $version = $this->activeCatalog();
        $this->entry($version, self::CODE_ELEVEN_DIGITS, 'Producto con código de 11 dígitos', '1%');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('productos.store'), $this->payload([
                'cabys_proposed_code' => self::CODE_ELEVEN_DIGITS,
                'tax_rate' => 1,
            ]))
            ->assertRedirect(route('productos.index'));

        $product = Product::query()->where('company_id', $company->id)->firstOrFail();
        $assignment = ProductCabysAssignment::query()
            ->where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame(ProductCabysAssignment::STATUS_CONFIRMED, $assignment->status);
        $this->assertSame(self::CODE_ELEVEN_DIGITS, $product->cabys_code);
    }

    public function test_code_longer_than_thirteen_digits_is_rejected_by_validation(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.ver']);
        $this->activeCatalog();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('productos.store'), $this->payload([
                'cabys_proposed_code' => '01111000001009',
            ]))
            ->assertSessionHasErrors('cabys_proposed_code');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_regime_of_one_company_does_not_affect_another(): void
    {
        [$companyA] = $this->context('Alfa');
        [$companyB] = $this->context('Beta');
        $this->activeCatalog();
        $this->regime($companyA, CompanyFiscalConfig::TAX_REGIME_SIMPLIFIED);

        $productB = $this->product($companyB);
        $productB->forceFill(['tax_rate' => null, 'tax_rate_source' => 'manual'])->save();
        $proposalB = $this->confirm($companyB, $productB, self::CODE_ONE_PERCENT);

        $productA = $this->product($companyA);
        $productA->forceFill(['tax_rate' => null, 'tax_rate_source' => 'manual'])->save();
        $proposalA = $this->confirm($companyA, $productA, self::CODE_ONE_PERCENT);

        $this->assertSame('applied', $proposalB['status']);
        $this->assertEquals(1.0, (float) $productB->fresh()->tax_rate);

        $this->assertSame('recorded', $proposalA['status']);
        $this->assertNull($productA->fresh()->tax_rate);
        $this->assertEquals(1.0, (float) $productA->fresh()->tax_rate_official_pct);
    }

    public function test_form_shows_the_regime_with_official_and_applied_tax_separated(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.editar']);
        $this->activeCatalog();
        $this->regime($company, CompanyFiscalConfig::TAX_REGIME_SIMPLIFIED);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.create'))
            ->assertOk()
            ->assertSee('Régimen fiscal')
            ->assertSee('Simplificado')
            ->assertSee('Tarifa oficial CABYS')
            ->assertSee('Impuesto aplicado al producto')
            ->assertSee('NO se autoaplica');

        $product = $this->product($company);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.edit', $product))
            ->assertOk()
            ->assertSee('Régimen fiscal')
            ->assertSee('Tarifa oficial CABYS');
    }

    /**
     * Confirmación de CABYS vía servicio: `suggest()` + `confirmWithProposal()`,
     * que es el camino que lee el régimen de la empresa.
     *
     * @return array<string, mixed>
     */
    private function confirm(Company $company, Product $product, string $code): array
    {
        $userId = User::factory()->create(['is_active' => true])->id;

        app(ProductCabysService::class)->suggest($company, $product, $code);

        return app(ProductCabysService::class)
            ->confirmWithProposal($company, $product->fresh(), $userId)['proposal'];
    }

    private function regime(Company $company, string $regime): CompanyFiscalConfig
    {
        return CompanyFiscalConfig::query()->updateOrCreate(
            ['company_id' => $company->id],
            [
                'tax_regime' => $regime,
                'tax_regime_source' => CompanyFiscalConfig::TAX_REGIME_SOURCE_MANUAL,
                'tax_regime_verified_at' => now(),
            ]
        );
    }

    private function payload(array $overrides = []): array
    {
        $id = uniqid();
        $category = ProductCategory::firstOrCreate(
            ['company_id' => $this->companyId, 'slug' => 'cat-'.$id],
            ['company_id' => $this->companyId, 'name' => 'Categoría '.$id, 'is_active' => true]
        );
        $unit = Unit::firstOrCreate(
            ['company_id' => $this->companyId, 'slug' => 'u-'.$id],
            ['company_id' => $this->companyId, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'is_active' => true]
        );

        return array_merge([
            'name' => 'Producto régimen '.uniqid(),
            'internal_code' => 'P-'.uniqid(),
            'product_type' => 'product',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'cost' => 1000,
            'sale_price' => 2000,
            'tax_rate' => 13,
            'track_inventory' => 1,
            'is_active' => 1,
        ], $overrides);
    }

    private function activeCatalog(): FiscalCatalogVersion
    {
        $version = FiscalCatalogVersion::create([
            'kind' => FiscalCatalogVersion::KIND_CABYS,
            'source' => 'BCCR CABYS',
            'source_version' => 'v'.uniqid(),
            'status' => FiscalCatalogVersion::STATUS_ACTIVE,
            'row_count' => 3,
            'imported_at' => now(),
            'activated_at' => now(),
        ]);

        $this->entry($version, self::CODE_ONE_PERCENT, 'Papel bond carta 20 lb', '1%');
        $this->entry($version, '0111100000200', 'Papel bond carta 75 lb', '2%');
        $this->entry($version, '0111100000900', 'Papel sin tarifa declarada', 'Según ley');

        return $version;
    }

    private function entry(FiscalCatalogVersion $version, string $code, string $description, ?string $raw): CabysCatalogEntry
    {
        return CabysCatalogEntry::create([
            'fiscal_catalog_version_id' => $version->id,
            'code' => $code,
            'description' => $description,
            'tax_rate_raw' => $raw,
            'is_active' => true,
        ]);
    }

    private function context(string $name = 'Empresa'): array
    {
        $company = Company::create(['trade_name' => $name.' '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $this->companyId = (int) $company->id;

        return [$company, $branch];
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Productos', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function profile(string $taxCode, string $rateCode): FiscalProfile
    {
        return FiscalProfile::query()
            ->where('tax_code', $taxCode)
            ->where('tax_rate_code', $rateCode)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function product(Company $company, array $attributes = []): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Categoría '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$id, 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'is_active' => true]);

        $product = Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$id,
            'internal_code' => 'P-'.$id,
            'product_type' => 'product',
            'cost' => 100,
            'sale_price' => 200,
            'tax_rate' => 13,
            'is_active' => true,
        ], $attributes));

        DB::table('branch_product')->insert([
            'branch_id' => $company->branches()->value('id'),
            'product_id' => $product->id,
            'stock' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $product;
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
