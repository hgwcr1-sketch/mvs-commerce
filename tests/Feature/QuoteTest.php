<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_stores_exact_snapshots_without_sale_payment_or_inventory_changes(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company, ['sale_price' => 1000, 'cost' => 600, 'tax_rate' => 13, 'barcode' => '789', 'cabys_code' => '1234567890123']);
        $this->stock($branch, $product, 5);

        $response = $this->createQuote($user, $company, $branch, $product, ['quantity' => 2, 'unit_price' => 900, 'discount' => 100, 'discount_type' => 'fixed']);
        $response->assertCreated()->assertJsonPath('quote_number', 'COT-00000001');
        $quote = Quote::with('items')->firstOrFail();
        $item = $quote->items->first();

        $this->assertSame('active', $quote->status);
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame($product->internal_code, $item->product_code);
        $this->assertSame('789', $item->barcode);
        $this->assertSame('1234567890123', $item->cabys_code);
        $this->assertSame($product->name, $item->description);
        $this->assertSame('U', $item->unit_code);
        $this->assertSame('2.0000', $item->quantity);
        $this->assertSame('900.0000', $item->unit_price);
        $this->assertSame('1800.0000', $item->gross_total);
        $this->assertSame('100.0000', $item->discount_total);
        $this->assertSame('1700.0000', $item->subtotal);
        $this->assertSame('13.0000', $item->tax_rate);
        $this->assertSame('221.0000', $item->tax_total);
        $this->assertSame('1921.0000', $item->total);
        $this->assertSame('600.0000', $item->unit_cost);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertEquals(5, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_permissions_history_detail_print_and_company_branch_isolation(): void
    {
        [$company, $branch, $user] = $this->context();
        $product = $this->product($company);
        $quoteId = $this->createQuote($user, $company, $branch, $product)->json('quote_id');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(route('cotizaciones.index'))->assertOk()->assertSee('COT-00000001');
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(route('cotizaciones.show', $quoteId))->assertOk()->assertSee($product->name);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->get(route('cotizaciones.print', $quoteId))->assertOk()->assertSee('COTIZACIÓN')->assertSee('NO ES COMPROBANTE FISCAL');

        $otherBranch = $this->branch($company, 'Otra');
        $user->branches()->attach($otherBranch);
        $this->actingAs($user)->withSession($this->activeSession($company, $otherBranch))->get(route('cotizaciones.show', $quoteId))->assertNotFound();
        [$otherCompany, $foreignBranch, $foreignUser] = $this->context('Ajena');
        $this->actingAs($foreignUser)->withSession($this->activeSession($otherCompany, $foreignBranch))->get(route('cotizaciones.show', $quoteId))->assertNotFound();

        $withoutPermission = $this->user($company, $branch, ['pos.acceder']);
        $this->actingAs($withoutPermission)->withSession($this->activeSession($company, $branch))->get(route('cotizaciones.index'))->assertForbidden();
        $this->createQuote($withoutPermission, $company, $branch, $product)->assertForbidden();
    }

    public function test_cancellation_is_logical_and_cancelled_or_expired_quote_cannot_convert(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $quote = Quote::findOrFail($this->createQuote($user, $company, $branch, $product)->json('quote_id'));
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->post(route('cotizaciones.cancel', $quote), ['cancellation_reason' => 'Cliente desistió'])->assertRedirect();
        $quote->refresh();
        $this->assertSame(Quote::STATUS_CANCELLED, $quote->status);
        $this->assertSame($user->id, $quote->cancelled_by);
        $this->assertNotNull($quote->cancelled_at);
        $this->checkoutQuote($user, $company, $branch, $cash, $quote, $product)->assertUnprocessable();

        $expired = Quote::findOrFail($this->createQuote($user, $company, $branch, $product, [], ['expires_at' => today()->subDay()->toDateString()])->json('quote_id'));
        $this->checkoutQuote($user, $company, $branch, $cash, $expired, $product)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_loading_and_conversion_use_immutable_database_snapshots_and_ignore_manipulated_payload(): void
    {
        [$company, $branch, $user, $cash] = $this->context('Empresa', ['pos.acceder', 'ventas.crear', 'cotizaciones.ver', 'cotizaciones.crear', 'cotizaciones.editar', 'pos.cambiar_precio', 'pos.aplicar_descuento']);
        $product = $this->product($company, ['sale_price' => 1000, 'cost' => 400, 'tax_rate' => 13]);
        $other = $this->product($company, ['sale_price' => 9999]);
        $this->stock($branch, $product, 10);
        $quote = Quote::findOrFail($this->createQuote($user, $company, $branch, $product, ['quantity' => 2, 'unit_price' => 800, 'discount' => 100, 'discount_type' => 'fixed'])->json('quote_id'));
        $original = $quote->load('items')->toArray();

        $limited = $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'cotizaciones.crear']);
        $this->actingAs($limited)->withSession($this->activeSession($company, $branch))->get(route('cotizaciones.load', $quote))->assertOk()->assertJsonPath('items.0.unit_price', 800)->assertJsonPath('items.0.quantity', 2);
        $product->update(['sale_price' => 5000, 'cost' => 2000, 'tax_rate' => 1]);

        $response = $this->checkoutQuote($limited, $company, $branch, $cash, $quote, $other, ['quantity' => 99, 'unit_price' => 1, 'discount' => 0, 'discount_type' => 'percentage', 'discount_total' => 0]);
        $response->assertOk();
        $sale = Sale::with('items')->firstOrFail();
        $item = $sale->items->first();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('2.0000', $item->quantity);
        $this->assertSame('800.0000', $item->unit_price);
        $this->assertSame('100.0000', $item->discount_total);
        $this->assertSame('400.0000', $item->unit_cost);
        $quote->refresh();
        $this->assertSame(Quote::STATUS_CONVERTED, $quote->status);
        $this->assertSame($sale->id, $quote->converted_sale_id);
        $this->assertNotNull($quote->converted_at);
        $this->assertEquals(8, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertSame($original['items'], $quote->fresh()->load('items')->toArray()['items']);
        $this->checkoutQuote($limited, $company, $branch, $cash, $quote, $product)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 1);
        $this->assertEquals(8, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    public function test_failed_checkout_leaves_quote_active_and_creates_nothing(): void
    {
        [$company, $branch, $user, $cash] = $this->context();
        $product = $this->product($company);
        $this->stock($branch, $product, 1);
        $quote = Quote::findOrFail($this->createQuote($user, $company, $branch, $product, ['quantity' => 2])->json('quote_id'));
        $this->checkoutQuote($user, $company, $branch, $cash, $quote, $product)->assertUnprocessable();
        $this->assertSame(Quote::STATUS_ACTIVE, $quote->fresh()->status);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertEquals(1, DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
    }

    private function context(string $name = 'Empresa', array $permissions = ['pos.acceder', 'ventas.crear', 'cotizaciones.ver', 'cotizaciones.crear', 'cotizaciones.editar', 'pos.cambiar_precio', 'pos.aplicar_descuento']): array
    {
        $company = Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = $this->branch($company, 'Principal');
        $user = $this->user($company, $branch, $permissions);
        $cash = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash-'.uniqid(), 'name' => 'Efectivo', 'type' => 'cash', 'is_active' => true, 'allows_change' => true]);

        return [$company, $branch, $user, $cash];
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => $name.'-'.$company->id, 'is_active' => true]);
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        } $user->companies()->attach($company, ['role_id' => $role->id]);
        $user->branches()->attach($branch);

        return $user;
    }

    private function product(Company $company, array $attributes = []): Product
    {
        $id = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$id, 'slug' => 'cat-'.$id, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$id, 'allows_decimals' => false, 'is_active' => true]);

        return Product::create(array_merge(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$id, 'internal_code' => 'P-'.$id, 'cost' => 500, 'sale_price' => 1000, 'tax_rate' => 13, 'track_inventory' => true, 'is_active' => true], $attributes));
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function createQuote(User $user, Company $company, Branch $branch, Product $product, array $line = [], array $extra = [])
    {
        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('cotizaciones.store'), array_merge(['items' => [array_merge(['product_id' => $product->id, 'quantity' => 1], $line)]], $extra));
    }

    private function checkoutQuote(User $user, Company $company, Branch $branch, PaymentMethod $cash, Quote $quote, Product $payloadProduct, array $line = [])
    {
        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), ['checkout_token' => (string) Str::uuid(), 'quote_id' => $quote->id, 'payments' => [['payment_method_id' => $cash->id, 'amount' => round((float) $quote->total), 'received_amount' => round((float) $quote->total)]], 'items' => [array_merge(['product_id' => $payloadProduct->id, 'quantity' => 1], $line)]]);
    }
}
