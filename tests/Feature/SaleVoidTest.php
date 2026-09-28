<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Sales\SaleVoidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleVoidTest extends TestCase
{
    use RefreshDatabase;

    public function test_void_sale_restores_stock_voids_payments_and_registers_inventory_movement(): void
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'PR-'.$company->id.'-'.uniqid(),
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoría '.uniqid(),
            'slug' => 'categoria-'.uniqid(),
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Unidad',
            'abbreviation' => 'U',
            'slug' => 'u-'.uniqid(),
            'allows_decimals' => false,
            'is_active' => true,
        ]);

        $product = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto de prueba',
            'internal_code' => 'P-'.uniqid(),
            'cost' => 500,
            'sale_price' => 1000,
            'stock' => 10,
            'tax_rate' => 0,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => null,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'venta-anulacion'),
            'sale_number' => 'POS-VOID-001',
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 2000,
            'discount_total' => 0,
            'tax_total' => 0,
            'rounding_total' => 0,
            'total' => 2000,
            'paid_total' => 2000,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_code' => $product->internal_code,
            'description' => $product->name,
            'unit_code' => 'U',
            'quantity' => 2,
            'unit_price' => 1000,
            'gross_total' => 2000,
            'discount_total' => 0,
            'subtotal' => 2000,
            'tax_rate' => 0,
            'tax_total' => 0,
            'total' => 2000,
            'unit_cost' => 500,
        ]);

        $cash = PaymentMethod::create([
            'company_id' => $company->id,
            'code' => 'cash-'.uniqid(),
            'name' => 'Efectivo',
            'type' => 'cash',
            'is_active' => true,
            'affects_cash' => true,
            'allows_change' => true,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'cash_session_id' => null,
            'payment_method_id' => $cash->id,
            'affects_cash_snapshot' => true,
            'created_by' => $user->id,
            'amount' => 2000,
            'received_amount' => 2000,
            'change_amount' => 0,
            'cash_effect_amount' => 2000,
            'reference' => null,
            'status' => SalePayment::STATUS_COMPLETED,
        ]);

        app(InventoryPostingService::class)->postSale(
            $sale,
            $product,
            2,
        );

        $this->assertSame(
            8.0,
            (float) DB::table('branch_product')
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->value('stock'),
        );

        $this->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);

        app(SaleVoidService::class)->void(
            $sale,
            $user,
            'Error en la venta',
        );

        $sale->refresh();

        $this->assertSame(
            Sale::STATUS_VOIDED,
            $sale->status,
        );

        $this->assertSame(
            $user->id,
            $sale->voided_by,
        );

        $this->assertSame(
            'Error en la venta',
            $sale->void_reason,
        );

        $payment = SalePayment::where('sale_id', $sale->id)
            ->firstOrFail();

        $this->assertSame(
            SalePayment::STATUS_VOIDED,
            $payment->status,
        );

        $this->assertSame(
            $user->id,
            $payment->voided_by,
        );

        $this->assertSame(
            10.0,
            (float) DB::table('branch_product')
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->value('stock'),
        );

        $this->assertDatabaseHas('inventory_movements', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'type' => 'sale_void',
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
            'reason' => 'Entrada por anulación de venta',
        ]);

               $this->assertSame(
            1,
            DB::table('inventory_movements')
                ->where('reference_type', Sale::class)
                ->where('reference_id', $sale->id)
                ->where('type', 'sale_void')
                ->count(),
        );

        try {
            app(SaleVoidService::class)->void(
                $sale,
                $user,
                'Segundo intento',
            );

            $this->fail('La venta no debe poder anularse dos veces.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame(
                'Solo se puede anular una venta completada.',
                collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertSame(
            10.0,
            (float) DB::table('branch_product')
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->value('stock'),
        );

        $this->assertSame(
            1,
            DB::table('inventory_movements')
                ->where('reference_type', Sale::class)
                ->where('reference_id', $sale->id)
                ->where('type', 'sale_void')
                ->count(),
        );
    }

    public function test_void_route_requires_permission_and_voids_sale_with_reason(): void
{
    $company = Company::create([
        'trade_name' => 'Empresa '.uniqid(),
        'currency' => 'CRC',
        'timezone' => 'America/Costa_Rica',
        'is_active' => true,
    ]);

    $branch = Branch::create([
        'company_id' => $company->id,
        'name' => 'Principal',
        'code' => 'PR-'.$company->id.'-'.uniqid(),
        'is_active' => true,
    ]);

    $saleOwner = User::factory()->create();

    $sale = Sale::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $saleOwner->id,
        'customer_id' => null,
        'checkout_token' => (string) Str::uuid(),
        'request_fingerprint' => hash('sha256', 'venta-http-anulacion'),
        'sale_number' => 'POS-VOID-HTTP-001',
        'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
        'sale_condition' => Sale::CONDITION_CASH,
        'status' => Sale::STATUS_COMPLETED,
        'currency_code' => 'CRC',
        'exchange_rate' => 1,
        'subtotal' => 1000,
        'discount_total' => 0,
        'tax_total' => 0,
        'rounding_total' => 0,
        'total' => 1000,
        'paid_total' => 1000,
        'balance_due' => 0,
        'completed_at' => now(),
    ]);

    $withoutPermission = User::factory()->create();

    $roleWithoutPermission = Role::create([
        'company_id' => $company->id,
        'name' => 'Sin anular '.uniqid(),
        'is_active' => true,
    ]);

    $withoutPermission->companies()->attach(
        $company->id,
        ['role_id' => $roleWithoutPermission->id],
    );

    $withoutPermission->branches()->attach($branch->id);

    $this->actingAs($withoutPermission)
        ->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])
        ->post(route('ventas.void', $sale), [
            'reason' => 'Error de prueba',
        ])
        ->assertForbidden();

    $this->assertSame(
        Sale::STATUS_COMPLETED,
        $sale->fresh()->status,
    );

    $authorized = User::factory()->create();

    $role = Role::create([
        'company_id' => $company->id,
        'name' => 'Puede anular '.uniqid(),
        'is_active' => true,
    ]);

    $permission = Permission::firstOrCreate(
        ['name' => 'ventas.anular'],
        [
            'label' => 'Anular ventas',
            'module' => 'Ventas',
            'is_active' => true,
        ],
    );

    $role->permissions()->syncWithoutDetaching($permission);

    $authorized->companies()->attach(
        $company->id,
        ['role_id' => $role->id],
    );

    $authorized->branches()->attach($branch->id);

    $response = $this->actingAs($authorized)
        ->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])
        ->post(route('ventas.void', $sale), [
            'reason' => 'Venta registrada por error',
        ]);

    $response->assertRedirect(route('ventas.show', $sale));

    $sale->refresh();

    $this->assertSame(
        Sale::STATUS_VOIDED,
        $sale->status,
    );

    $this->assertSame(
        $authorized->id,
        $sale->voided_by,
    );

    $this->assertSame(
        'Venta registrada por error',
        $sale->void_reason,
    );
}

}