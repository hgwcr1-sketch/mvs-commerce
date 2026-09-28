<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CabysCatalogEntry;
use App\Models\Company;
use App\Models\FiscalCatalogVersion;
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
 * C) CABYS EN PRODUCTO + D) CABYS → IMPUESTO.
 *
 * El código CABYS nunca se escribe a mano: sale del catálogo local
 * versionado. Estas pruebas fijan las reglas que no pueden romperse:
 *
 *  - sin catálogo activo nada se inventa;
 *  - un código de la versión vigente queda `confirmed`, uno que no existe
 *    queda `pending` y el producto se guarda igual;
 *  - la búsqueda es la misma ruta en alta y en edición;
 *  - la tarifa oficial se aplica SOLO si no contradice una tarifa manual;
 *  - una empresa nunca lee ni escribe la asignación de otra.
 */
class ProductCabysTest extends TestCase
{
    use RefreshDatabase;

    private const CODE_ONE_PERCENT = '0111100000100';

    private const CODE_TWO_PERCENT = '0111100000200';

    private const CODE_NO_RATE = '0111100000900';

    private ?int $companyId = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_search_returns_entries_of_the_active_catalog_version(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $version = $this->activeCatalog();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('productos.cabys.search', ['q' => 'Papel']))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('error', null)
            ->assertJsonPath('version', $version->source_version)
            ->assertJsonPath('entries.0.code', self::CODE_ONE_PERCENT)
            ->assertJsonPath('entries.0.tax_rate_pct', 1);
    }

    public function test_search_without_active_catalog_reports_no_catalog_instead_of_failing(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('productos.cabys.search', ['q' => 'Papel']))
            ->assertOk()
            ->assertJsonPath('found', false)
            ->assertJsonPath('error', 'no_catalog')
            ->assertJsonPath('entries', []);
    }

    public function test_search_requires_product_permission(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['clientes.ver']);
        $this->activeCatalog();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('productos.cabys.search', ['q' => 'Papel']))
            ->assertForbidden();
    }

    public function test_search_ignores_entries_of_superseded_versions(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.ver']);
        $active = $this->activeCatalog();

        $superseded = FiscalCatalogVersion::create([
            'kind' => FiscalCatalogVersion::KIND_CABYS,
            'source' => 'BCCR CABYS',
            'source_version' => 'VIEJA',
            'status' => FiscalCatalogVersion::STATUS_SUPERSEDED,
            'activated_at' => now()->subYear(),
        ]);
        $this->entry($superseded, '0111100000999', 'Papel viejo', '13%');

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('productos.cabys.search', ['q' => 'Papel viejo']));

        $response->assertOk()->assertJsonPath('found', false);
        $this->assertNotSame($active->id, $superseded->id);
    }

    public function test_create_confirms_the_selected_code_from_the_catalog(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.ver']);
        $version = $this->activeCatalog();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('productos.store'), $this->payload([
                'cabys_proposed_code' => self::CODE_ONE_PERCENT,
                'tax_rate' => 1,
            ]))
            ->assertRedirect(route('productos.index'));

        $product = Product::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame(self::CODE_ONE_PERCENT, $product->cabys_code);

        $assignment = ProductCabysAssignment::query()
            ->where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame(ProductCabysAssignment::STATUS_CONFIRMED, $assignment->status);
        $this->assertSame($version->id, $assignment->fiscal_catalog_version_id);
        $this->assertSame($user->id, $assignment->confirmed_by);
    }

    public function test_edit_selects_and_confirms_a_different_code(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.editar', 'productos.ver']);
        $this->activeCatalog();
        $product = $this->product($company);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('productos.update', $product), $this->payload([
                'internal_code' => $product->internal_code,
                'cabys_proposed_code' => self::CODE_TWO_PERCENT,
                'tax_rate' => 2,
            ]))
            ->assertRedirect(route('productos.index'));

        $assignment = ProductCabysAssignment::query()
            ->where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame(ProductCabysAssignment::STATUS_CONFIRMED, $assignment->status);
        $this->assertSame(self::CODE_TWO_PERCENT, $assignment->code);
        $this->assertSame(self::CODE_TWO_PERCENT, $product->fresh()->cabys_code);
    }

    public function test_edit_keeps_previous_code_as_traceability_when_changing(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.editar', 'productos.ver']);
        $this->activeCatalog();
        $product = $this->product($company);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('productos.update', $product), $this->payload([
                'internal_code' => $product->internal_code,
                'cabys_proposed_code' => self::CODE_ONE_PERCENT,
                'tax_rate' => 1,
            ]))
            ->assertRedirect(route('productos.index'));

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('productos.update', $product->fresh()), $this->payload([
                'internal_code' => $product->internal_code,
                'cabys_proposed_code' => self::CODE_TWO_PERCENT,
                'tax_rate' => 2,
            ]))
            ->assertRedirect(route('productos.index'));

        $assignment = ProductCabysAssignment::query()
            ->where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame(self::CODE_ONE_PERCENT, $assignment->previous_code);
        $this->assertSame(self::CODE_TWO_PERCENT, $assignment->code);
    }

    public function test_code_outside_the_active_version_stays_pending_and_product_is_saved(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.ver']);
        $this->activeCatalog();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('productos.store'), $this->payload([
                'cabys_proposed_code' => '0999999999999',
                'tax_rate' => 13,
            ]))
            ->assertRedirect(route('productos.index'))
            ->assertSessionHas('success');

        $product = Product::query()->where('company_id', $company->id)->firstOrFail();

        $assignment = ProductCabysAssignment::query()
            ->where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame(ProductCabysAssignment::STATUS_PENDING, $assignment->status);
        $this->assertNull($assignment->confirmed_at);
        $this->assertNull($product->cabys_code);
    }

    public function test_free_text_code_is_rejected_by_validation(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.ver']);
        $this->activeCatalog();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('productos.store'), $this->payload([
                'cabys_proposed_code' => 'CABYS-123',
            ]))
            ->assertSessionHasErrors('cabys_proposed_code');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_product_without_catalog_saves_without_cabys(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.ver']);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('productos.store'), $this->payload([
                'cabys_proposed_code' => self::CODE_ONE_PERCENT,
                'tax_rate' => 13,
            ]))
            ->assertRedirect(route('productos.index'));

        $this->assertDatabaseCount('products', 1);
        $product = Product::query()->where('company_id', $company->id)->firstOrFail();

        $assignment = ProductCabysAssignment::query()
            ->where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame(ProductCabysAssignment::STATUS_PENDING, $assignment->status);
        $this->assertNull($assignment->fiscal_catalog_version_id);
        $this->assertNull(app(ProductCabysService::class)->codeFor($company, $product));
        $this->assertNull($product->cabys_code);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'tax_rate' => 13,
        ]);
    }

    public function test_pending_assignment_is_never_used_as_a_sale_code(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.editar', 'productos.ver']);
        $this->activeCatalog();
        $product = $this->product($company);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('productos.update', $product), $this->payload([
                'internal_code' => $product->internal_code,
                'cabys_proposed_code' => '0999999999999',
                'tax_rate' => 13,
            ]))
            ->assertRedirect(route('productos.index'));

        $this->assertNull(app(ProductCabysService::class)->codeFor($company, $product->fresh()));
        $this->assertNull($product->fresh()->cabys_code);
    }

    public function test_assignment_is_isolated_between_companies(): void
    {
        [$companyA, $branchA] = $this->context('Alfa');
        [$companyB, $branchB] = $this->context('Beta');
        $this->companyId = (int) $companyA->id;
        $this->activeCatalog();
        $product = $this->product($companyB);

        $this->actingAs($this->user($companyA, $branchA, ['productos.editar', 'productos.ver']))
            ->withSession($this->activeSession($companyA, $branchA))
            ->put(route('productos.update', $product), $this->payload([
                'internal_code' => $product->internal_code,
                'cabys_proposed_code' => self::CODE_ONE_PERCENT,
            ]))
            ->assertNotFound();

        $this->assertDatabaseCount('product_cabys_assignments', 0);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'cabys_code' => null,
        ]);
    }

    public function test_confirmed_code_records_official_rate_when_the_product_tax_matches(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.ver']);
        $this->activeCatalog();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('productos.store'), $this->payload([
                'cabys_proposed_code' => self::CODE_ONE_PERCENT,
                'tax_rate' => 1,
            ]))
            ->assertRedirect(route('productos.index'));

        $product = Product::query()->where('company_id', $company->id)->firstOrFail();

        $this->assertEquals(1.0, (float) $product->tax_rate);
        $this->assertSame('cabys_confirmed', $product->tax_rate_source);
        $this->assertEquals(1.0, (float) $product->tax_rate_official_pct);
        $this->assertSame('1%', $product->tax_rate_official_raw);
    }

    public function test_confirmed_code_applies_official_rate_when_the_product_has_no_tax_rate(): void
    {
        [$company, $branch] = $this->context();
        $this->activeCatalog();
        $product = $this->product($company);
        $product->forceFill(['tax_rate' => null, 'tax_rate_source' => 'manual'])->save();

        $service = app(ProductCabysService::class);
        $entry = CabysCatalogEntry::query()->where('code', self::CODE_ONE_PERCENT)->firstOrFail();

        $proposal = $service->proposeTaxRate($company, $product->fresh(), $entry);

        $this->assertSame('applied', $proposal['status']);
        $product->refresh();
        $this->assertEquals(1.0, (float) $product->tax_rate);
        $this->assertSame('cabys_confirmed', $product->tax_rate_source);
    }

    public function test_confirmed_code_applies_official_rate_on_edit_when_tax_comes_from_cabys(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.editar', 'productos.ver']);
        $this->activeCatalog();
        $product = $this->product($company, ['tax_rate' => 1]);
        $product->forceFill(['tax_rate_source' => 'cabys_confirmed'])->save();
        $this->assertSame('cabys_confirmed', $product->fresh()->tax_rate_source);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('productos.update', $product), $this->payload([
                'internal_code' => $product->internal_code,
                'cabys_proposed_code' => self::CODE_TWO_PERCENT,
                'tax_rate' => 1,
            ]))
            ->assertRedirect(route('productos.index'));

        $product->refresh();

        $this->assertEquals(2.0, (float) $product->tax_rate);
        $this->assertSame('cabys_confirmed', $product->tax_rate_source);
        $this->assertEquals(2.0, (float) $product->tax_rate_official_pct);
    }

    public function test_manual_tax_rate_is_never_overwritten_by_a_confirmed_code(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.editar', 'productos.ver']);
        $this->activeCatalog();
        $product = $this->product($company, ['tax_rate' => 13]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('productos.update', $product), $this->payload([
                'internal_code' => $product->internal_code,
                'cabys_proposed_code' => self::CODE_ONE_PERCENT,
                'tax_rate' => 13,
            ]))
            ->assertRedirect(route('productos.index'));

        $product->refresh();

        $this->assertEquals(13.0, (float) $product->tax_rate);
        $this->assertSame('manual', $product->tax_rate_source);
        $this->assertEquals(1.0, (float) $product->tax_rate_official_pct);
        $this->assertSame('1%', $product->tax_rate_official_raw);

        $assignment = ProductCabysAssignment::query()
            ->where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame(ProductCabysAssignment::STATUS_CONFIRMED, $assignment->status);
    }

    public function test_changing_the_tax_rate_in_the_form_marks_it_as_manual_again(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.editar', 'productos.ver']);
        $this->activeCatalog();
        $product = $this->product($company, ['tax_rate' => 1]);
        $product->forceFill(['tax_rate_source' => 'cabys_confirmed'])->save();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('productos.update', $product), $this->payload([
                'internal_code' => $product->internal_code,
                'tax_rate' => 13,
            ]))
            ->assertRedirect(route('productos.index'));

        $product->refresh();

        $this->assertEquals(13.0, (float) $product->tax_rate);
        $this->assertSame('manual', $product->tax_rate_source);
    }

    public function test_code_without_interpretable_rate_does_not_invent_a_tax_rate(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.ver']);
        $this->activeCatalog();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('productos.store'), $this->payload([
                'cabys_proposed_code' => self::CODE_NO_RATE,
                'tax_rate' => 13,
            ]))
            ->assertRedirect(route('productos.index'));

        $product = Product::query()->where('company_id', $company->id)->firstOrFail();

        $this->assertEquals(13.0, (float) $product->tax_rate);
        $this->assertSame('manual', $product->tax_rate_source);
        $this->assertNull($product->tax_rate_official_pct);
        $this->assertNull($product->tax_rate_official_raw);
        $this->assertSame(self::CODE_NO_RATE, $product->cabys_code);
    }

    public function test_form_renders_the_cabys_searcher_and_the_current_state(): void
    {
        [$company, $branch] = $this->context();
        $user = $this->user($company, $branch, ['productos.crear', 'productos.editar', 'productos.ver']);
        $this->activeCatalog();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.create'))
            ->assertOk()
            ->assertSee('name="cabys_proposed_code"', false)
            ->assertSee(route('productos.cabys.search'), false);

        $product = $this->product($company);
        ProductCabysAssignment::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'code' => self::CODE_ONE_PERCENT,
            'source' => ProductCabysAssignment::SOURCE_MANUAL,
            'status' => ProductCabysAssignment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('productos.edit', $product))
            ->assertOk()
            ->assertSee(self::CODE_ONE_PERCENT)
            ->assertSee('Confirmado contra el cat');
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
            'name' => 'Papel Bond Carta',
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
        $this->entry($version, self::CODE_TWO_PERCENT, 'Papel bond carta 75 lb', '2%');
        $this->entry($version, self::CODE_NO_RATE, 'Papel sin tarifa declarada', 'Según ley');

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
