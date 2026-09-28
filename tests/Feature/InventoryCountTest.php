<?php

namespace Tests\Feature;

use App\Http\Controllers\InventoryCountController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InventoryCountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $user;

    private Product $product;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->company = Company::create(['trade_name' => 'Conteos', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $this->branch = Branch::create(['company_id' => $this->company->id, 'name' => 'Principal', 'code' => 'MAIN', 'is_active' => true]);
        $this->role = Role::create(['company_id' => $this->company->id, 'name' => 'Contador', 'is_active' => true]);
        foreach (['ver', 'iniciar', 'contar', 'revisar', 'confirmar', 'cancelar'] as $action) {
            $permission = Permission::firstOrCreate(['name' => 'inventario.conteo.'.$action], ['label' => $action, 'module' => 'Inventario', 'is_active' => true]);
            $this->role->permissions()->attach($permission);
        }
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->companies()->attach($this->company->id, ['role_id' => $this->role->id]);
        $this->user->branches()->attach($this->branch);
        $category = ProductCategory::create(['company_id' => $this->company->id, 'name' => 'General', 'slug' => 'general', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $this->company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'unidad', 'allows_decimals' => true, 'is_active' => true]);
        $this->product = Product::create(['company_id' => $this->company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Arroz integral', 'internal_code' => 'SKU-COUNT', 'barcode' => '744100000001', 'cost' => '10', 'sale_price' => '20', 'tax_rate' => '0', 'track_inventory' => true, 'is_active' => true]);
        $this->branch->products()->attach($this->product, ['stock' => '10.0000']);
        $this->actingAs($this->user)->withSession(['active_company_id' => $this->company->id, 'active_branch_id' => $this->branch->id]);
    }

    private function countDocument(string $status = 'draft', ?Branch $branch = null): InventoryCount
    {
        $branch ??= $this->branch;

        return InventoryCount::create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'status' => $status, 'reference' => 'COUNT-'.Str::random(8)])->refresh();
    }

    private function item(InventoryCount $count): InventoryCountItem
    {
        return $count->items()->create(['product_id' => $this->product->id, 'theoretical_quantity' => '10.0000', 'difference' => '0.0000'])->refresh();
    }

    private function stock(): string
    {
        return bcadd((string) DB::table('branch_product')->where('branch_id', $this->branch->id)->where('product_id', $this->product->id)->value('stock'), '0', 4);
    }

    public static function permissions(): array
    {
        return [['ver', 'GET', 'index'], ['iniciar', 'POST', 'store'], ['contar', 'POST', 'add-item'], ['revisar', 'POST', 'review'], ['confirmar', 'POST', 'confirm'], ['cancelar', 'POST', 'cancel']];
    }

    #[DataProvider('permissions')]
    public function test_permission_is_required(string $permission, string $method, string $action): void
    {
        $count = $this->countDocument($action === 'confirm' ? 'review' : 'counting');
        $this->role->permissions()->detach(Permission::where('name', 'inventario.conteo.'.$permission)->value('id'));
        $this->actingAs($this->user->fresh())->json($method, route('inventory-counts.'.$action, in_array($action, ['index', 'store']) ? [] : [$count]), ['search' => $this->product->barcode])->assertForbidden();
        $this->assertSame('10.0000', $this->stock());
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame($count->status, $count->fresh()->status);
    }

    public function test_navigation_and_authorized_read_pages(): void
    {
        $count = $this->countDocument();
        $this->get(route('inventory-counts.index'))->assertOk()->assertSee('Toma de Inventario')->assertSee(route('inventory-counts.index'), false);
        foreach (['create', 'edit', 'show'] as $action) {
            $this->get(route('inventory-counts.'.$action, $action === 'create' ? [] : [$count]))->assertOk();
        }
        $sidebar = file_get_contents(resource_path('views/components/navigation/sidebar.blade.php'));
        $this->assertMatchesRegularExpression('/label="Inventario">(?:(?!<\/x-navigation.dropdown>).)*label="Toma de Inventario"/s', $sidebar);
    }

    public function test_creation_records_actor_and_time_without_stock_changes(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->post(route('inventory-counts.store'), ['reference' => 'FINAL'])->assertRedirect();
        $count = InventoryCount::sole();
        $this->assertSame('draft', $count->status);
        $this->assertEquals($this->user->id, $count->started_by);
        $this->assertTrue($count->started_at->equalTo(now()));
        $this->assertEquals($this->company->id, $count->company_id);
        $this->assertEquals($this->branch->id, $count->branch_id);
        $this->assertSame('10.0000', $this->stock());
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public static function searches(): array
    {
        return [['integral'], ['SKU-COUNT'], ['744100000001'], ['744100000099']];
    }

    #[DataProvider('searches')]
    public function test_manual_and_scanner_search_snapshot_and_duplicate(string $search): void
    {
        ProductBarcode::create(['product_id' => $this->product->id, 'barcode' => '744100000099', 'barcode_type' => 'EAN13', 'is_active' => true, 'is_primary' => false]);
        $count = $this->countDocument();
        $url = route('inventory-counts.add-item', $count);
        $this->postJson($url, ['search' => $search])->assertOk()->assertJsonPath('exists', false)->assertJsonPath('item.product_id', $this->product->id)->assertJsonPath('item.theoretical_quantity', '10.0000');
        $item = $count->items()->sole();
        $this->assertSame('10.0000', $item->theoretical_quantity);
        $this->postJson($url, ['search' => $search])->assertOk()->assertJsonPath('exists', true)->assertJsonPath('item_id', $item->id);
        $this->assertDatabaseCount('inventory_count_items', 1);
        $this->assertSame('10.0000', $this->stock());
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public static function quantities(): array
    {
        return [['8', '-2.0000'], ['12', '2.0000'], ['10', '0.0000']];
    }

    #[DataProvider('quantities')]
    public function test_count_recount_and_confirmation(string $physical, string $difference): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);
        $this->putJson(route('inventory-counts.update-quantity', [$count, $item]), ['counted_quantity' => $physical])->assertOk()->assertJsonPath('difference', $difference);
        $this->assertSame('counting', $count->fresh()->status);
        $this->assertSame(number_format((float) $physical, 4, '.', ''), $item->fresh()->counted_quantity);
        $this->assertSame(number_format((float) $physical, 4, '.', ''), $item->fresh()->final_quantity);
        $this->assertSame($difference, $item->fresh()->difference);
        $this->assertSame('10.0000', $this->stock());
        $this->post(route('inventory-counts.review', $count))->assertRedirect();
        $this->assertSame('review', $count->fresh()->status);
        $this->post(route('inventory-counts.confirm', $count))->assertRedirect(route('inventory-counts.show', $count));
        $this->assertSame(number_format((float) $physical, 4, '.', ''), $this->stock());
        $this->assertSame('confirmed', $count->fresh()->status);
        $this->assertEquals($this->user->id, $count->fresh()->confirmed_by);
        $this->assertNotNull($count->fresh()->confirmed_at);
        $expected = $difference === '0.0000' ? 0 : 1;
        $this->assertDatabaseCount('inventory_movements', $expected);
        if ($expected) {
            $movement = InventoryMovement::sole();
            $this->assertSame('adjustment', $movement->type);
            $this->assertSame('10.0000', $movement->previous_stock);
            $this->assertSame(number_format((float) $physical, 4, '.', ''), $movement->new_stock);
            $this->assertSame($difference, $movement->quantity);
            $this->assertSame(InventoryCount::class, $movement->reference_type);
            $this->assertEquals($count->id, $movement->reference_id);
            $this->assertEquals($this->company->id, $movement->company_id);
            $this->assertEquals($this->branch->id, $movement->branch_id);
        }
        $this->postJson(route('inventory-counts.confirm', $count))->assertStatus(422);
        $this->assertSame(number_format((float) $physical, 4, '.', ''), $this->stock());
        $this->assertDatabaseCount('inventory_movements', $expected);
    }

    public function test_recount_preserves_original_and_recalculates_four_decimals(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);
        $this->putJson(route('inventory-counts.update-quantity', [$count, $item]), ['counted_quantity' => '8'])->assertOk();
        $this->putJson(route('inventory-counts.recount', [$count, $item]), ['recount_quantity' => '12.0001'])->assertOk()->assertJsonPath('difference', '2.0001');
        $item->refresh();
        $this->assertSame('8.0000', $item->counted_quantity);
        $this->assertSame('12.0001', $item->recount_quantity);
        $this->assertSame('12.0001', $item->final_quantity);
        $this->assertSame('2.0001', $item->difference);
        foreach (['-1', '1.00001', 'abc'] as $invalid) {
            $this->put(route('inventory-counts.update-quantity', [$count, $item]), ['counted_quantity' => $invalid])->assertRedirect()->assertSessionHasErrors('counted_quantity');
        }
        $this->assertSame($item->getAttributes(), $item->fresh()->getAttributes());
        $this->post(route('inventory-counts.review', $count))->assertRedirect();
        $this->post(route('inventory-counts.confirm', $count))->assertRedirect();
        $this->assertSame('12.0001', $this->stock());
        $this->assertSame('8.0000', $item->fresh()->counted_quantity);
        $this->assertSame('2.0001', InventoryMovement::sole()->quantity);
    }

    public static function invalidTransitions(): array
    {
        return [['draft', 'confirm'], ['counting', 'confirm'], ['confirmed', 'cancel'], ['cancelled', 'confirm'], ['confirmed', 'back-to-counting']];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_is_blocked(string $status, string $action): void
    {
        $count = $this->countDocument($status);
        $this->postJson(route('inventory-counts.'.$action, $count))->assertStatus(422);
        $this->assertSame($status, $count->fresh()->status);
        $this->assertSame('10.0000', $this->stock());
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_draft_review_redirects_with_error_when_uncounted(): void
    {
        $count = $this->countDocument();
        $this->item($count);

        $this->post(route('inventory-counts.review', $count))->assertRedirect()->assertSessionHas('error');

        $this->assertSame('draft', $count->fresh()->status);
        $this->assertSame('10.0000', $this->stock());
    }

    public function test_draft_review_succeeds_when_all_items_counted(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);
        $this->putJson(route('inventory-counts.update-quantity', [$count, $item]), ['counted_quantity' => '10'])->assertOk();

        $this->post(route('inventory-counts.review', $count))->assertRedirect()->assertSessionHas('success');

        $this->assertSame('review', $count->fresh()->status);
    }

    public function test_review_redirects_to_show_not_edit(): void
    {
        $count = $this->countDocument('counting');
        $item = $this->item($count);
        $item->update(['counted_quantity' => '10', 'final_quantity' => '10', 'difference' => '0.0000']);

        $response = $this->post(route('inventory-counts.review', $count));
        $response->assertRedirect(route('inventory-counts.show', $count));
        $response->assertSessionHas('success', 'Toma enviada a revisión.');

        $this->assertSame('review', $count->fresh()->status);

        $this->get(route('inventory-counts.edit', $count))->assertStatus(422);
    }

    public function test_cancel_records_actor_and_time_without_stock_changes(): void
    {
        $this->travelTo(now()->startOfSecond());
        $count = $this->countDocument('counting');
        $this->item($count)->update(['counted_quantity' => '12', 'final_quantity' => '12', 'difference' => '2']);
        $this->post(route('inventory-counts.cancel', $count))->assertRedirect();
        $this->assertSame('cancelled', $count->fresh()->status);
        $this->assertEquals($this->user->id, $count->fresh()->cancelled_by);
        $this->assertTrue($count->fresh()->cancelled_at->equalTo(now()));
        $this->assertSame('10.0000', $this->stock());
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public static function scopes(): array
    {
        return [['company'], ['branch']];
    }

    #[DataProvider('scopes')]
    public function test_foreign_document_and_its_items_are_isolated(string $scope): void
    {
        $company = $scope === 'company' ? Company::create(['trade_name' => 'Otra', 'is_active' => true]) : $this->company;
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Otra', 'code' => 'OTHER', 'is_active' => true]);
        $count = $this->countDocument('counting', $branch);
        $item = $this->item($count);
        $before = $count->getAttributes();
        $beforeItem = $item->getAttributes();
        $localCount = $this->countDocument();
        foreach (['update-quantity' => ['counted_quantity' => '12'], 'recount' => ['recount_quantity' => '12'], 'update-notes' => ['notes' => 'Intrusion']] as $action => $data) {
            $this->putJson(route('inventory-counts.'.$action, [$localCount, $item]), $data)->assertNotFound();
        }
        $this->get(route('inventory-counts.index'))->assertOk()->assertDontSee($count->reference);
        foreach (['show', 'edit'] as $action) {
            $this->getJson(route('inventory-counts.'.$action, $count))->assertNotFound();
        }
        foreach (['update-quantity' => ['counted_quantity' => '12'], 'recount' => ['recount_quantity' => '12'], 'update-notes' => ['notes' => 'Intrusion']] as $action => $data) {
            $this->putJson(route('inventory-counts.'.$action, [$count, $item]), $data)->assertNotFound();
        }
        $this->putJson(route('inventory-counts.update', $count), ['notes' => 'Intrusion'])->assertNotFound();
        foreach (['review', 'back-to-counting', 'confirm', 'cancel', 'add-item'] as $action) {
            $this->postJson(route('inventory-counts.'.$action, $count), ['search' => $this->product->barcode])->assertNotFound();
        }
        $this->deleteJson(route('inventory-counts.destroy', $count))->assertNotFound();
        $this->assertSame($before, $count->fresh()->getAttributes());
        $this->assertSame($beforeItem, $item->fresh()->getAttributes());
        $this->assertSame('10.0000', $this->stock());
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_item_from_another_count_cannot_be_updated(): void
    {
        $count = $this->countDocument();
        $item = $this->item($this->countDocument());
        foreach (['update-quantity' => ['counted_quantity' => '12'], 'recount' => ['recount_quantity' => '12'], 'update-notes' => ['notes' => 'Intrusion']] as $action => $data) {
            $this->putJson(route('inventory-counts.'.$action, [$count, $item]), $data)->assertNotFound();
        }
        $this->assertSame($item->getAttributes(), $item->fresh()->getAttributes());
    }

    public static function rejectedProducts(): array
    {
        return [['missing'], ['company'], ['branch'], ['inactive'], ['untracked']];
    }

    #[DataProvider('rejectedProducts')]
    public function test_invalid_product_search_cannot_add_item(string $case): void
    {
        $count = $this->countDocument();
        $barcode = 'EXTRA-COUNT';
        ProductBarcode::create(['product_id' => $this->product->id, 'barcode' => $barcode, 'barcode_type' => 'CODE128', 'is_active' => true]);
        if ($case === 'missing') {
            $barcode = 'DOES-NOT-EXIST';
        }
        if ($case === 'company') {
            $this->product->update(['company_id' => Company::create(['trade_name' => 'Otra', 'is_active' => true])->id]);
        }
        if ($case === 'branch') {
            $this->branch->products()->detach($this->product);
        }
        if ($case === 'inactive') {
            $this->product->update(['is_active' => false]);
        }
        if ($case === 'untracked') {
            $this->product->update(['track_inventory' => false]);
        }
        $this->post(route('inventory-counts.add-item', $count), ['search' => $barcode])->assertRedirect()->assertSessionHasErrors('search');
        $this->assertDatabaseCount('inventory_count_items', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_intermediate_sale_is_preserved_and_physical_count_replaces_current_stock(): void
    {
        $count = $this->countDocument();
        $this->postJson(route('inventory-counts.add-item', $count), ['search' => $this->product->barcode])->assertOk();
        $item = $count->items()->sole();
        $sale = Sale::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'user_id' => $this->user->id, 'checkout_token' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'count-sale'), 'sale_number' => 'POS-COUNT', 'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET, 'sale_condition' => Sale::CONDITION_CASH, 'status' => Sale::STATUS_COMPLETED, 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 40, 'discount_total' => 0, 'tax_total' => 0, 'rounding_total' => 0, 'total' => 40, 'paid_total' => 40, 'balance_due' => 0, 'completed_at' => now()]);
        $saleMovement = DB::transaction(fn () => app(InventoryPostingService::class)->postSale($sale, $this->product, 2));
        $before = $saleMovement->refresh()->getAttributes();
        $this->assertSame('8.0000', $this->stock());
        $this->putJson(route('inventory-counts.update-quantity', [$count, $item]), ['counted_quantity' => '12'])->assertOk();
        $this->post(route('inventory-counts.review', $count))->assertRedirect();
        $this->post(route('inventory-counts.confirm', $count))->assertRedirect();
        // Physical inventory is authoritative: current 8 becomes physical 12, adjustment +4.
        $this->assertSame('12.0000', $this->stock());
        $this->assertSame('10.0000', $item->fresh()->theoretical_quantity);
        $this->assertSame('2.0000', $item->fresh()->difference);
        $this->assertSame($before, $saleMovement->fresh()->getAttributes());
        $movement = InventoryMovement::where('type', 'adjustment')->sole();
        $this->assertSame('8.0000', $movement->previous_stock);
        $this->assertSame('12.0000', $movement->new_stock);
        $this->assertSame('4.0000', $movement->quantity);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public static function currentStockAdjustments(): array
    {
        return [
            'shortage' => ['5.0000', '2', '-3.0000'],
            'zero snapshot difference' => ['8.0000', '10', '2.0000'],
            'already physical' => ['8.0000', '8', '0.0000'],
        ];
    }

    #[DataProvider('currentStockAdjustments')]
    public function test_confirmation_compares_physical_with_current_stock(string $current, string $physical, string $adjustment): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);
        $this->putJson(route('inventory-counts.update-quantity', [$count, $item]), ['counted_quantity' => $physical])->assertOk();
        $snapshotDifference = bcsub($physical, '10.0000', 4);
        $this->branch->products()->updateExistingPivot($this->product->id, ['stock' => $current]);
        $this->post(route('inventory-counts.review', $count))->assertRedirect();
        $this->post(route('inventory-counts.confirm', $count))->assertRedirect();
        $this->assertSame(number_format((float) $physical, 4, '.', ''), $this->stock());
        $this->assertSame($snapshotDifference, $item->fresh()->difference);
        $this->assertSame('10.0000', $item->fresh()->theoretical_quantity);
        $expected = $adjustment === '0.0000' ? 0 : 1;
        $this->assertDatabaseCount('inventory_movements', $expected);
        if ($expected) {
            $movement = InventoryMovement::sole();
            $this->assertSame($current, $movement->previous_stock);
            $this->assertSame(number_format((float) $physical, 4, '.', ''), $movement->new_stock);
            $this->assertSame($adjustment, $movement->quantity);
        }
        $this->postJson(route('inventory-counts.confirm', $count))->assertStatus(422);
        $this->assertSame(number_format((float) $physical, 4, '.', ''), $this->stock());
        $this->assertDatabaseCount('inventory_movements', $expected);
    }

    public function test_mobile_camera_reuses_shared_scanner_and_responsive_markers(): void
    {
        $view = file_get_contents(resource_path('views/inventory-counts/edit.blade.php'));
        $this->assertSame(1, substr_count($view, '<x-scanner.mvs-scanner'));
        $this->assertStringContainsString('mvs-scanner-open', $view);
        $this->assertStringContainsString("addEventListener('mvs-scan'", $view);
        foreach (['getUserMedia', 'new BarcodeDetector', '<video'] as $parallelScanner) {
            $this->assertStringNotContainsString($parallelScanner, $view);
        }
        $this->assertStringNotContainsString('<x-scanner.mvs-scanner', file_get_contents(resource_path('views/inventory-counts/show.blade.php')));
        foreach (['index', 'create', 'edit', 'show'] as $page) {
            $this->assertStringContainsString('data-responsive="360 768 1280"', file_get_contents(resource_path('views/inventory-counts/'.$page.'.blade.php')));
        }
        $this->assertStringContainsString('min-h-11', $view);
    }

    public function test_confirmation_rechecks_a_document_loaded_before_another_confirmation(): void
    {
        $count = $this->countDocument('review');
        $this->item($count)->update(['counted_quantity' => '12', 'final_quantity' => '12', 'difference' => '2']);
        $stale = $count->fresh();
        $this->post(route('inventory-counts.confirm', $count))->assertRedirect();
        // Deterministic stale binding, not a claim of parallel database execution.
        try {
            app(InventoryCountController::class)->confirm($stale);
            $this->fail('A stale review document must not be confirmed twice.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame('12.0000', $this->stock());
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public static function autocompleteSearches(): array
    {
        return [
            'partial name' => ['Arroz'],
            'partial internal_code' => ['SKU'],
            'partial barcode' => ['7441'],
        ];
    }

    #[DataProvider('autocompleteSearches')]
    public function test_add_item_with_autocomplete_product_id(string $partialSearch): void
    {
        $count = $this->countDocument();
        $url = route('inventory-counts.add-item', $count);

        $this->postJson($url, ['product_id' => $this->product->id])
            ->assertOk()
            ->assertJsonPath('exists', false)
            ->assertJsonPath('item.product_id', $this->product->id);

        $this->assertDatabaseCount('inventory_count_items', 1);
    }

    #[DataProvider('autocompleteSearches')]
    public function test_add_item_with_partial_search_text(string $partialSearch): void
    {
        $count = $this->countDocument();
        $url = route('inventory-counts.add-item', $count);

        $this->postJson($url, ['search' => $partialSearch])
            ->assertOk()
            ->assertJsonPath('exists', false)
            ->assertJsonPath('item.product_id', $this->product->id);

        $this->assertDatabaseCount('inventory_count_items', 1);
    }

    public function test_add_item_empty_search_and_no_product_id(): void
    {
        $count = $this->countDocument();
        $url = route('inventory-counts.add-item', $count);

        $this->post($url, ['search' => ''])->assertRedirect()->assertSessionHasErrors('search');
        $this->post($url, ['search' => 'DOESNOTEXIST'])->assertRedirect()->assertSessionHasErrors('search');

        $this->assertDatabaseCount('inventory_count_items', 0);
    }

    public function test_mobile_cards_and_desktop_table_markers(): void
    {
        $view = file_get_contents(resource_path('views/inventory-counts/edit.blade.php'));

        $this->assertStringContainsString('md:hidden', $view);
        $this->assertStringContainsString('data-item-id', $view);
        $this->assertStringContainsString('type="number"', $view);
        $this->assertStringContainsString('inputmode="numeric"', $view);
        $this->assertStringContainsString('step="1"', $view);
        $this->assertStringContainsString('@method(\'PUT\')', $view);
        $this->assertStringContainsString('counted_quantity', $view);

        $this->assertStringContainsString('hidden md:block', $view);

        $this->assertStringContainsString('saveCountedQuantity', $view);
        $this->assertStringContainsString('data-save-desktop', $view);
        $this->assertStringContainsString('qty-input-desktop', $view);
    }

    public function test_add_item_duplicate_returns_existing_item_id(): void
    {
        $count = $this->countDocument();
        $url = route('inventory-counts.add-item', $count);

        $this->postJson($url, ['product_id' => $this->product->id])->assertOk()->assertJsonPath('exists', false);
        $this->postJson($url, ['product_id' => $this->product->id])->assertOk()->assertJsonPath('exists', true)->assertJsonStructure(['item_id']);

        $this->assertDatabaseCount('inventory_count_items', 1);
    }

    public function test_add_item_by_barcode_partial_match(): void
    {
        ProductBarcode::create(['product_id' => $this->product->id, 'barcode' => '744100000099', 'barcode_type' => 'EAN13', 'is_active' => true, 'is_primary' => false]);
        $count = $this->countDocument();
        $url = route('inventory-counts.add-item', $count);

        $this->postJson($url, ['search' => '7441000000'])->assertOk()->assertJsonPath('item.product_id', $this->product->id);
        $this->assertDatabaseCount('inventory_count_items', 1);
    }

    public function test_add_item_by_additional_barcode(): void
    {
        ProductBarcode::create(['product_id' => $this->product->id, 'barcode' => 'ADDITIONAL-BC', 'barcode_type' => 'CODE128', 'is_active' => true, 'is_primary' => false]);
        $count = $this->countDocument();
        $url = route('inventory-counts.add-item', $count);

        $this->postJson($url, ['search' => 'ADDITIONAL-BC'])->assertOk()->assertJsonPath('item.product_id', $this->product->id);
        $this->assertDatabaseCount('inventory_count_items', 1);
    }

    public function test_add_item_nonexistent_product_returns_422(): void
    {
        $count = $this->countDocument();
        $url = route('inventory-counts.add-item', $count);

        $this->post($url, ['search' => 'NONEXISTENT'])->assertRedirect()->assertSessionHasErrors('search');

        $this->assertDatabaseCount('inventory_count_items', 0);
    }

    public function test_clearing_existing_note_persists_null_in_db(): void
    {
        $count = $this->countDocument('counting');
        $item = $count->items()->create([
            'product_id' => $this->product->id,
            'theoretical_quantity' => '10.0000',
            'counted_quantity' => '10.0000',
            'final_quantity' => '10.0000',
            'difference' => '0.0000',
            'notes' => 'Revisión pendiente',
        ]);

        $this->assertSame('Revisión pendiente', $item->fresh()->notes);

        $this->putJson(route('inventory-counts.update-notes', [$count, $item]), ['notes' => ''])
            ->assertOk();

        $this->assertNull($item->fresh()->notes);

        $this->putJson(route('inventory-counts.update-notes', [$count, $item]), ['notes' => null])
            ->assertOk();

        $this->assertNull($item->fresh()->notes);
    }

    public function test_notes_input_has_data_original_attribute(): void
    {
        $count = $this->countDocument('counting');
        $count->items()->create([
            'product_id' => $this->product->id,
            'theoretical_quantity' => '10.0000',
            'notes' => 'Nota de prueba',
        ]);

        $response = $this->get(route('inventory-counts.edit', $count));
        $response->assertOk();
        $response->assertSee('Nota de prueba');
    }

    public function test_create_page_redirects_when_no_branch_selected(): void
    {
        $dashAdmin = Permission::firstOrCreate(['name' => 'dashboard.admin'], ['label' => 'Admin Dashboard', 'module' => 'Dashboard', 'is_active' => true]);
        $this->role->permissions()->attach($dashAdmin);
        $this->session(['active_branch_id' => null]);

        $this->get(route('inventory-counts.create'))
            ->assertRedirect(route('inventory-counts.index'))
            ->assertSessionHas('warning', 'Seleccione una sucursal para iniciar una toma de inventario.');

        $this->assertDatabaseCount('inventory_counts', 0);
    }

    public function test_store_redirects_when_no_branch_selected(): void
    {
        $dashAdmin = Permission::firstOrCreate(['name' => 'dashboard.admin'], ['label' => 'Admin Dashboard', 'module' => 'Dashboard', 'is_active' => true]);
        $this->role->permissions()->attach($dashAdmin);
        $this->session(['active_branch_id' => null]);

        $this->post(route('inventory-counts.store'), ['reference' => 'NO-BRANCH'])
            ->assertRedirect(route('inventory-counts.index'))
            ->assertSessionHas('warning', 'Seleccione una sucursal para iniciar una toma de inventario.');

        $this->assertDatabaseCount('inventory_counts', 0);
    }

    public function test_update_quantity_zero_persists_correctly(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);

        $this->putJson(route('inventory-counts.update-quantity', [$count, $item]), ['counted_quantity' => '0'])
            ->assertOk()
            ->assertJsonPath('difference', '-10.0000')
            ->assertJsonPath('final_quantity', '0.0000');

        $this->assertSame('0.0000', $item->fresh()->counted_quantity);
        $this->assertSame('0.0000', $item->fresh()->final_quantity);
        $this->assertSame('-10.0000', $item->fresh()->difference);
        $this->assertSame('10.0000', $this->stock());
    }

    public function test_update_quantity_rejects_decimal(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);

        $this->putJson(route('inventory-counts.update-quantity', [$count, $item]), ['counted_quantity' => '2.5'])
            ->assertStatus(422);

        $this->assertNull($item->fresh()->counted_quantity);
        $this->assertSame('10.0000', $this->stock());
    }

    public function test_stock_unchanged_after_saving_quantity(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);

        $this->assertSame('10.0000', $this->stock());
        $this->putJson(route('inventory-counts.update-quantity', [$count, $item]), ['counted_quantity' => '5'])->assertOk();
        $this->assertSame('10.0000', $this->stock());
        $this->putJson(route('inventory-counts.update-quantity', [$count, $item]), ['counted_quantity' => '0'])->assertOk();
        $this->assertSame('10.0000', $this->stock());
    }

    public function test_edit_page_renders_desktop_inputs_when_editable(): void
    {
        $count = $this->countDocument('counting');
        $count->items()->create([
            'product_id' => $this->product->id,
            'theoretical_quantity' => '10.0000',
            'counted_quantity' => '5.0000',
            'final_quantity' => '5.0000',
            'difference' => '-5.0000',
        ]);

        $response = $this->get(route('inventory-counts.edit', $count));
        $response->assertOk();
        $response->assertSee('qty-input-desktop', false);
        $response->assertSee('data-save-desktop', false);
        $response->assertSee('saveCountedQuantity', false);
    }

    public function test_edit_page_returns_422_for_non_editable_statuses(): void
    {
        foreach (['review', 'confirmed', 'cancelled'] as $status) {
            $count = $this->countDocument($status);
            $count->items()->create([
                'product_id' => $this->product->id,
                'theoretical_quantity' => '10.0000',
                'counted_quantity' => '5.0000',
                'final_quantity' => '5.0000',
                'difference' => '-5.0000',
            ]);

            $this->get(route('inventory-counts.edit', $count))->assertStatus(422);
        }
    }

    public function test_remove_item_in_draft(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);

        $this->assertDatabaseCount('inventory_count_items', 1);
        $this->assertSame('10.0000', $this->stock());

        $this->deleteJson(route('inventory-counts.remove-item', [$count, $item]))->assertOk();

        $this->assertDatabaseCount('inventory_count_items', 0);
        $this->assertSame('10.0000', $this->stock());
    }

    public function test_remove_item_in_counting(): void
    {
        $count = $this->countDocument('counting');
        $item = $this->item($count);

        $this->assertDatabaseCount('inventory_count_items', 1);

        $this->deleteJson(route('inventory-counts.remove-item', [$count, $item]))->assertOk();

        $this->assertDatabaseCount('inventory_count_items', 0);
        $this->assertSame('10.0000', $this->stock());
    }

    public function test_remove_item_does_not_change_stock(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);

        $this->assertSame('10.0000', $this->stock());
        $this->deleteJson(route('inventory-counts.remove-item', [$count, $item]))->assertOk();
        $this->assertSame('10.0000', $this->stock());
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_remove_item_rejected_in_review(): void
    {
        $count = $this->countDocument('review');
        $item = $this->item($count);

        $this->deleteJson(route('inventory-counts.remove-item', [$count, $item]))->assertStatus(422);
        $this->assertDatabaseCount('inventory_count_items', 1);
    }

    public function test_remove_item_rejected_in_confirmed(): void
    {
        $count = $this->countDocument('confirmed');
        $item = $this->item($count);

        $this->deleteJson(route('inventory-counts.remove-item', [$count, $item]))->assertStatus(422);
        $this->assertDatabaseCount('inventory_count_items', 1);
    }

    public function test_remove_item_rejected_in_cancelled(): void
    {
        $count = $this->countDocument('cancelled');
        $item = $this->item($count);

        $this->deleteJson(route('inventory-counts.remove-item', [$count, $item]))->assertStatus(422);
        $this->assertDatabaseCount('inventory_count_items', 1);
    }

    public function test_remove_item_from_other_count_rejected(): void
    {
        $count = $this->countDocument();
        $otherCount = $this->countDocument();
        $item = $this->item($otherCount);

        $this->deleteJson(route('inventory-counts.remove-item', [$count, $item]))->assertStatus(404);
        $this->assertDatabaseCount('inventory_count_items', 1);
    }

    public function test_remove_item_respects_company_and_branch(): void
    {
        $otherCompany = Company::create(['trade_name' => 'Otra', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $otherBranch = Branch::create(['company_id' => $otherCompany->id, 'name' => 'Sucursal2', 'code' => 'B2', 'is_active' => true]);
        $otherCount = InventoryCount::create(['company_id' => $otherCompany->id, 'branch_id' => $otherBranch->id, 'status' => 'draft', 'reference' => 'X']);
        $item = $otherCount->items()->create(['product_id' => $this->product->id, 'theoretical_quantity' => '5.0000', 'difference' => '0.0000']);

        $count = $this->countDocument();

        $this->deleteJson(route('inventory-counts.remove-item', [$count, $item]))->assertStatus(404);
        $this->assertDatabaseCount('inventory_count_items', 1);
    }

    public function test_mobile_form_submit_saves_five(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);

        $this->put(route('inventory-counts.update-quantity', [$count, $item]), [
            'counted_quantity' => '5',
        ])->assertRedirect();

        $this->assertSame('5.0000', $item->fresh()->counted_quantity);
        $this->assertSame('5.0000', $item->fresh()->final_quantity);
        $this->assertSame('-5.0000', $item->fresh()->difference);
        $this->assertSame('counting', $count->fresh()->status);
    }

    public function test_mobile_form_submit_saves_zero(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);

        $this->put(route('inventory-counts.update-quantity', [$count, $item]), [
            'counted_quantity' => '0',
        ])->assertRedirect();

        $this->assertSame('0.0000', $item->fresh()->counted_quantity);
        $this->assertSame('-10.0000', $item->fresh()->difference);
    }

    public function test_mobile_form_submit_rejects_decimal(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);

        $this->put(route('inventory-counts.update-quantity', [$count, $item]), [
            'counted_quantity' => '3.5',
        ])->assertRedirect()->assertSessionHasErrors('counted_quantity');

        $this->assertNull($item->fresh()->counted_quantity);
    }

    public function test_mobile_form_persists_on_reload(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);

        $this->put(route('inventory-counts.update-quantity', [$count, $item]), [
            'counted_quantity' => '7',
        ])->assertRedirect();

        $this->get(route('inventory-counts.edit', $count))
            ->assertOk()
            ->assertSee('value="7"', false);
    }

    public function test_mobile_form_does_not_change_stock(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);

        $this->assertSame('10.0000', $this->stock());
        $this->put(route('inventory-counts.update-quantity', [$count, $item]), [
            'counted_quantity' => '3',
        ])->assertRedirect();
        $this->assertSame('10.0000', $this->stock());
    }

    public function test_mobile_form_delete_still_works(): void
    {
        $count = $this->countDocument();
        $item = $this->item($count);

        $this->put(route('inventory-counts.update-quantity', [$count, $item]), [
            'counted_quantity' => '5',
        ])->assertRedirect();

        $this->assertDatabaseCount('inventory_count_items', 1);
        $this->deleteJson(route('inventory-counts.remove-item', [$count, $item]))->assertOk();
        $this->assertDatabaseCount('inventory_count_items', 0);
        $this->assertSame('10.0000', $this->stock());
    }

    public function test_mobile_form_rejected_in_review_status(): void
    {
        $count = $this->countDocument('review');
        $item = $this->item($count);

        $this->put(route('inventory-counts.update-quantity', [$count, $item]), [
            'counted_quantity' => '5',
        ])->assertStatus(422);
    }

    public function test_review_blocks_when_one_item_uncounted(): void
    {
        $count = $this->countDocument('counting');
        $item1 = $this->item($count);

        $product2 = Product::create(['company_id' => $this->company->id, 'category_id' => $this->product->category_id, 'unit_id' => $this->product->unit_id, 'name' => 'P2', 'internal_code' => 'SKU-2', 'barcode' => '744100000002', 'cost' => '10', 'sale_price' => '20', 'tax_rate' => '0', 'track_inventory' => true, 'is_active' => true]);
        $item2 = $count->items()->create(['product_id' => $product2->id, 'theoretical_quantity' => '5.0000', 'difference' => '0.0000']);

        $item1->update(['counted_quantity' => '10', 'final_quantity' => '10', 'difference' => '0.0000']);

        $this->post(route('inventory-counts.review', $count))->assertRedirect()->assertSessionHas('error', 'Debe registrar la cantidad física de 1 producto antes de enviar a revisión.');

        $this->assertSame('counting', $count->fresh()->status);
    }

    public function test_review_shows_correct_count_of_uncounted_products(): void
    {
        $count = $this->countDocument('counting');
        $this->item($count);

        $product2 = Product::create(['company_id' => $this->company->id, 'category_id' => $this->product->category_id, 'unit_id' => $this->product->unit_id, 'name' => 'P2', 'internal_code' => 'SKU-2', 'barcode' => '744100000002', 'cost' => '10', 'sale_price' => '20', 'tax_rate' => '0', 'track_inventory' => true, 'is_active' => true]);
        $product3 = Product::create(['company_id' => $this->company->id, 'category_id' => $this->product->category_id, 'unit_id' => $this->product->unit_id, 'name' => 'P3', 'internal_code' => 'SKU-3', 'barcode' => '744100000003', 'cost' => '10', 'sale_price' => '20', 'tax_rate' => '0', 'track_inventory' => true, 'is_active' => true]);
        $count->items()->create(['product_id' => $product2->id, 'theoretical_quantity' => '5.0000', 'difference' => '0.0000']);
        $count->items()->create(['product_id' => $product3->id, 'theoretical_quantity' => '3.0000', 'difference' => '0.0000']);

        $this->post(route('inventory-counts.review', $count))->assertRedirect()->assertSessionHas('error', 'Debe registrar la cantidad física de 3 productos antes de enviar a revisión.');
    }

    public function test_review_error_is_friendly_not_422_page(): void
    {
        $count = $this->countDocument();

        $response = $this->post(route('inventory-counts.review', $count));
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->get(route('inventory-counts.edit', $count))->assertOk()->assertSee('error');
    }

    public function test_newest_product_first_in_edit_view(): void
    {
        $count = $this->countDocument();
        $item1 = $count->items()->create(['product_id' => $this->product->id, 'theoretical_quantity' => '10.0000', 'difference' => '0.0000']);

        $product2 = Product::create(['company_id' => $this->company->id, 'category_id' => $this->product->category_id, 'unit_id' => $this->product->unit_id, 'name' => 'P2', 'internal_code' => 'SKU-2', 'barcode' => '744100000002', 'cost' => '10', 'sale_price' => '20', 'tax_rate' => '0', 'track_inventory' => true, 'is_active' => true]);
        $item2 = $count->items()->create(['product_id' => $product2->id, 'theoretical_quantity' => '5.0000', 'difference' => '0.0000']);

        $product3 = Product::create(['company_id' => $this->company->id, 'category_id' => $this->product->category_id, 'unit_id' => $this->product->unit_id, 'name' => 'P3', 'internal_code' => 'SKU-3', 'barcode' => '744100000003', 'cost' => '10', 'sale_price' => '20', 'tax_rate' => '0', 'track_inventory' => true, 'is_active' => true]);
        $item3 = $count->items()->create(['product_id' => $product3->id, 'theoretical_quantity' => '3.0000', 'difference' => '0.0000']);

        $response = $this->get(route('inventory-counts.edit', $count));
        $response->assertOk();

        $body = $response->getContent();
        $pos3 = strpos($body, 'data-item-id="'.$item3->id.'"');
        $pos2 = strpos($body, 'data-item-id="'.$item2->id.'"');
        $pos1 = strpos($body, 'data-item-id="'.$item1->id.'"');

        $this->assertNotFalse($pos3);
        $this->assertNotFalse($pos2);
        $this->assertNotFalse($pos1);
        $this->assertLessThan($pos2, $pos3, 'Item 3 (newest) should appear before Item 2');
        $this->assertLessThan($pos1, $pos2, 'Item 2 should appear before Item 1 (oldest)');
    }

    public function test_edit_view_review_form_is_independent_and_not_nested(): void
    {
        $count = $this->countDocument('counting');
        $this->item($count);

        $response = $this->get(route('inventory-counts.edit', $count));
        $response->assertOk();

        $html = $response->getContent();

        $reviewAction = route('inventory-counts.review', $count);
        $this->assertStringContainsString('action="'.$reviewAction.'"', $html);
        $this->assertStringContainsString('Enviar a Revisión', $html);

        $events = [];
        preg_match_all('/<form[\s>]/i', $html, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $match) {
            $events[] = ['type' => 'open', 'pos' => $match[1]];
        }
        preg_match_all('/<\/form>/i', $html, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $match) {
            $events[] = ['type' => 'close', 'pos' => $match[1]];
        }

        usort($events, fn($a, $b) => $a['pos'] <=> $b['pos']);

        $reviewFormOpenPos = strpos($html, '<form method="POST" action="'.$reviewAction);
        $this->assertNotFalse($reviewFormOpenPos, 'Review form <form> tag not found');

        $depth = 0;
        foreach ($events as $event) {
            if ($event['type'] === 'open') {
                $depth++;
            } else {
                $depth--;
            }
            if ($event['pos'] === $reviewFormOpenPos) {
                break;
            }
            if ($event['type'] === 'open' && $event['pos'] < $reviewFormOpenPos && $depth > 1) {
                $this->fail("Review form is nested inside another form (depth {$depth} at pos {$event['pos']})");
            }
        }

        $this->assertGreaterThanOrEqual(1, $depth);
    }

    public function test_newest_product_first_with_three_products(): void
    {
        $count = $this->countDocument();
        $first = $count->items()->create(['product_id' => $this->product->id, 'theoretical_quantity' => '1.0000', 'difference' => '0.0000']);

        $product2 = Product::create(['company_id' => $this->company->id, 'category_id' => $this->product->category_id, 'unit_id' => $this->product->unit_id, 'name' => 'P2', 'internal_code' => 'SKU-2', 'barcode' => '744100000002', 'cost' => '10', 'sale_price' => '20', 'tax_rate' => '0', 'track_inventory' => true, 'is_active' => true]);
        $second = $count->items()->create(['product_id' => $product2->id, 'theoretical_quantity' => '2.0000', 'difference' => '0.0000']);

        $product3 = Product::create(['company_id' => $this->company->id, 'category_id' => $this->product->category_id, 'unit_id' => $this->product->unit_id, 'name' => 'P3', 'internal_code' => 'SKU-3', 'barcode' => '744100000003', 'cost' => '10', 'sale_price' => '20', 'tax_rate' => '0', 'track_inventory' => true, 'is_active' => true]);
        $third = $count->items()->create(['product_id' => $product3->id, 'theoretical_quantity' => '3.0000', 'difference' => '0.0000']);

        $response = $this->get(route('inventory-counts.edit', $count));
        $body = $response->getContent();

        $posThird = strpos($body, 'data-item-id="'.$third->id.'"');
        $posSecond = strpos($body, 'data-item-id="'.$second->id.'"');
        $posFirst = strpos($body, 'data-item-id="'.$first->id.'"');

        $this->assertLessThan($posSecond, $posThird);
        $this->assertLessThan($posFirst, $posSecond);
    }
}
