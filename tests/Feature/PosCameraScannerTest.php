<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCameraScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_renders_camera_scanner_button_and_sheet_with_permission(): void
    {
        [$company, $branch, $user] = $this->context();

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->getContent();

        // Botón táctil junto a la búsqueda.
        $this->assertStringContainsString('aria-label="Escanear código con cámara"', $html);
        $this->assertStringContainsString('@click="$dispatch(\'mvs-scanner-open\', { videoId: \'pos-scanner-video\' })"', $html);

        // Hoja mobile-safe del escáner con controles obligatorios.
        $this->assertStringContainsString('x-data="mvsScanner"', $html);
        $this->assertStringContainsString('aria-label="Cerrar escáner"', $html);
        $this->assertStringContainsString('@keydown.escape.window="close()"', $html);
        $this->assertStringContainsString('id="pos-scanner-video"', $html);
        $this->assertStringContainsString('playsinline', $html);
        $this->assertStringContainsString('muted', $html);
    }

    public function test_scan_event_is_consumed_by_existing_search_flow(): void
    {
        [$company, $branch, $user] = $this->context();

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->getContent();

        // El POS consume el evento común mvs-scan y entrega el código a la búsqueda existente.
        $this->assertStringContainsString('@mvs-scan.window="onMvsScan($event)"', $html);
        $this->assertStringContainsString('onMvsScan(event) {', $html);
        $this->assertStringContainsString('this.query = code;', $html);
        $this->assertStringContainsString('this.searchProducts();', $html);
    }

    public function test_valid_code_flows_through_existing_barcode_match_and_add_product(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company, ['barcode' => '7441000000017', 'name' => 'Producto Escaneado']);
        $product->branches()->attach($branch->id, ['stock' => 3]);

        // La búsqueda existente resuelve el código escaneado por matched_barcode.
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.products.search', ['q' => '7441000000017']))
            ->assertOk()
            ->assertJsonPath('0.matched_barcode', '7441000000017')
            ->assertJsonPath('0.can_add_to_cart', true);

        // El auto-agregado por coincidencia exacta sigue viviendo solo en searchProducts.
        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->getContent();
        $this->assertSame(1, substr_count($html, 'product.matched_barcode === term'));
    }

    public function test_qr_url_is_not_treated_as_product_code(): void
    {
        $engine = file_get_contents(base_path('resources/js/scanner/engine.js'));

        // El gate de QR rechaza URLs y esquemas antes de emitir cualquier evento.
        $this->assertStringContainsString('export function isProductCodeText', $engine);
        $this->assertStringContainsString('/^[a-z][a-z0-9+.-]*:/i', $engine);
        $this->assertStringContainsString("value.includes('://')", $engine);
        $this->assertStringContainsString('/^www\\./i', $engine);

        // El gate se aplica únicamente sobre resultados qr_code.
        $this->assertStringContainsString("format === 'qr_code' && !isProductCodeText(rawValue)", $engine);

        // El componente nunca navega ni busca por su cuenta; solo emite el evento.
        $index = file_get_contents(base_path('resources/js/scanner/index.js'));
        $this->assertStringNotContainsString('window.location', $index);
        $this->assertStringNotContainsString('fetch(', $index);
    }

    public function test_scanner_does_not_create_second_cart_or_product_state(): void
    {
        [$company, $branch, $user] = $this->context();

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->getContent();

        // Un solo carrito y un solo addProduct: el escáner no duplica estado.
        $this->assertSame(1, substr_count($html, 'cart: []'));
        $this->assertSame(1, substr_count($html, 'addProduct(product) {'));

        foreach (['engine.js', 'index.js'] as $file) {
            $source = file_get_contents(base_path('resources/js/scanner/'.$file));
            $this->assertStringNotContainsString('cart', $source);
            $this->assertStringNotContainsString('addProduct', $source);
        }
    }

    public function test_pos_works_normally_without_camera_support(): void
    {
        [$company, $branch, $user] = $this->context();
        $this->product($company, ['name' => 'Buscable Sin Camara']);

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->getContent();

        // El acceso a cámara está condicionado en runtime y el escáner inicia cerrado.
        $this->assertStringContainsString('x-show="cameraScannerAvailable"', $html);
        $this->assertStringContainsString('cameraScannerAvailable: false', $html);
        $this->assertStringContainsString('open: false', file_get_contents(base_path('resources/js/scanner/index.js')));

        // Búsqueda manual intacta.
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.products.search', ['q' => 'Buscable Sin Camara']))
            ->assertOk()
            ->assertJsonPath('0.name', 'Buscable Sin Camara');
    }

    public function test_camera_failures_show_clear_messages_without_breaking_pos(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk();

        $engine = file_get_contents(base_path('resources/js/scanner/engine.js'));
        foreach ([
            'Permiso de cámara denegado',
            'No se encontró una cámara disponible',
            'cámara está siendo usada por otra aplicación',
            'conexión segura (HTTPS)',
            'navegador no admite el uso de la cámara',
            'No fue posible iniciar la cámara',
        ] as $message) {
            $this->assertStringContainsString($message, $engine);
        }
    }

    public function test_hid_keyboard_and_desktop_flow_remain_intact(): void
    {
        [$company, $branch, $user] = $this->context();

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->getContent();

        // Flujo HID: input → Enter → addSelected → addProduct (sin cambios).
        $this->assertStringContainsString('@keydown.enter.prevent="addSelected"', $html);
        $this->assertStringContainsString('handleGlobalEnter($event)', $html);
        $this->assertStringContainsString('placeholder="Buscar por nombre, código o escanear código de barras…"', $html);

        // El escáner no intercepta teclado ni declara atajos globales propios.
        foreach (['engine.js', 'index.js'] as $file) {
            $source = file_get_contents(base_path('resources/js/scanner/'.$file));
            $this->assertStringNotContainsString('keydown', $source);
        }
    }

    public function test_r02a_mobile_layout_guards_remain_intact(): void
    {
        [$company, $branch, $user] = $this->context();

        $html = $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->get(route('pos.index'))
            ->assertOk()
            ->getContent();

        // Barra sticky Total/Cobrar sigue presente y ahora respeta también el escáner abierto.
        $this->assertStringContainsString('id="pos-sticky-bar"', $html);
        $this->assertStringContainsString('!quickCustomer.open && !cameraScannerOpen', $html);

        // Foco condicionado por puntero fino (R02-A) sin cambios.
        $this->assertStringContainsString('(hover: hover) and (pointer: fine)', $html);

        // Carrito tarjeta móvil y composición tablet (R02-A) sin cambios.
        $this->assertStringContainsString('grid-cols-[minmax(0,1fr)_auto]', $html);
        $this->assertStringContainsString('md:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]', $html);

        // Enter global no abre checkout mientras el escáner está abierto.
        $this->assertStringContainsString('|| this.cameraScannerOpen || this.resultsOpen', $html);
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'is_active' => true]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal '.$company->id,
            'code' => 'P-'.$company->id,
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol '.uniqid(),
            'is_active' => true,
        ]);
        $permission = Permission::firstOrCreate(
            ['name' => 'pos.acceder'],
            ['label' => 'Acceder al POS', 'module' => 'POS', 'is_active' => true],
        );
        $role->permissions()->attach($permission);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        app(CompanyCashSettingsProvisioner::class)->provision($company);
        $register = CashRegister::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'code' => 'CAJA-'.uniqid(),
            'name' => 'Caja principal',
            'is_active' => true,
        ]);
        CashSession::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'cash_register_id' => $register->id,
            'session_number' => 'CAJA-'.uniqid(),
            'opened_by' => $user->id,
            'status' => CashSession::STATUS_OPEN,
            'open_guard' => CashSession::OPEN_GUARD,
            'opening_amount' => 0,
            'opened_at' => now(),
        ]);

        return [$company, $branch, $user];
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function product(Company $company, array $attributes = []): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoría '.$suffix,
            'slug' => 'category-'.$suffix,
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Unidad '.$suffix,
            'abbreviation' => 'U',
            'slug' => 'unit-'.$suffix,
            'is_active' => true,
        ]);

        return Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix,
            'internal_code' => 'CODE-'.$suffix,
            'sale_price' => 1000,
            'tax_rate' => 13,
            'track_inventory' => true,
            'is_active' => true,
        ], $attributes));
    }
}
