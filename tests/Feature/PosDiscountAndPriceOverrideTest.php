<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosDiscountAndPriceOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_line_discount_is_applied_before_tax(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
        ]);

        $product = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 13,
        ]);

        $response = $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
                'discount' => 100,
                'discount_type' => 'fixed',
            ]],
            1017,
        );

        $response->assertOk();

        $sale = Sale::with('items')->firstOrFail();
        $item = $sale->items->first();

        $this->assertSame('1000.0000', $item->gross_total);
        $this->assertSame('100.0000', $item->discount_total);
        $this->assertSame('900.0000', $item->subtotal);
        $this->assertSame('117.0000', $item->tax_total);
        $this->assertSame('1017.0000', $item->total);

        $this->assertSame('900.0000', $sale->subtotal);
        $this->assertSame('100.0000', $sale->discount_total);
        $this->assertSame('117.0000', $sale->tax_total);
        $this->assertSame('1017.0000', $sale->total);
    }

    public function test_percentage_line_discount_is_applied_before_tax(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
        ]);

        $product = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 13,
        ]);

        $response = $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
                'discount' => 10,
                'discount_type' => 'percentage',
            ]],
            1017,
        );

        $response->assertOk();

        $sale = Sale::with('items')->firstOrFail();
        $item = $sale->items->first();

        $this->assertSame('100.0000', $item->discount_total);
        $this->assertSame('900.0000', $item->subtotal);
        $this->assertSame('117.0000', $item->tax_total);
        $this->assertSame('1017.0000', $item->total);
    }

    public function test_fixed_general_discount_is_distributed_before_tax(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
        ]);

        $product = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 13,
        ]);

        $response = $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
            1017,
            [
                'discount_total' => 100,
                'discount_total_type' => 'fixed',
            ],
        );

        $response->assertOk();

        $sale = Sale::with('items')->firstOrFail();
        $item = $sale->items->first();

        $this->assertSame('100.0000', $sale->discount_total);
        $this->assertSame('900.0000', $sale->subtotal);
        $this->assertSame('117.0000', $sale->tax_total);
        $this->assertSame('1017.0000', $sale->total);

        $this->assertSame('100.0000', $item->discount_total);
        $this->assertSame('900.0000', $item->subtotal);
        $this->assertSame('117.0000', $item->tax_total);
    }

    public function test_percentage_general_discount_is_applied_before_tax(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
        ]);

        $product = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 13,
        ]);

        $response = $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
            1017,
            [
                'discount_total' => 10,
                'discount_total_type' => 'percentage',
            ],
        );

        $response->assertOk();

        $sale = Sale::with('items')->firstOrFail();

        $this->assertSame('100.0000', $sale->discount_total);
        $this->assertSame('900.0000', $sale->subtotal);
        $this->assertSame('117.0000', $sale->tax_total);
        $this->assertSame('1017.0000', $sale->total);
    }

    public function test_user_without_discount_permission_cannot_manipulate_line_discount(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
        ]);

        $product = $this->product($company);

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
                'discount' => 100,
                'discount_type' => 'fixed',
            ]],
            5000,
        )->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_user_without_discount_permission_cannot_manipulate_general_discount(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
        ]);

        $product = $this->product($company);

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
            5000,
            [
                'discount_total' => 100,
                'discount_total_type' => 'fixed',
            ],
        )->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_percentage_over_one_hundred_is_rejected(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
        ]);

        $product = $this->product($company);

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
                'discount' => 101,
                'discount_type' => 'percentage',
            ]],
            5000,
        )->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_fixed_discount_above_line_amount_is_rejected(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
        ]);

        $product = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 0,
        ]);

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
                'discount' => 1001,
                'discount_type' => 'fixed',
            ]],
            5000,
        )->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }
        public function test_manual_price_with_permission_is_used_and_product_price_stays_unchanged(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.cambiar_precio',
        ]);

        $product = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 13,
        ]);

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 800,
            ]],
            904,
        )->assertOk();

        $sale = Sale::with('items')->firstOrFail();
        $item = $sale->items->first();

        $this->assertSame('800.0000', $item->unit_price);
        $this->assertSame('800.0000', $item->gross_total);
        $this->assertSame('800.0000', $item->subtotal);
        $this->assertSame('104.0000', $item->tax_total);
        $this->assertSame('904.0000', $item->total);

        $this->assertSame('1000.00', $product->fresh()->sale_price);
    }

    public function test_user_without_price_permission_cannot_manipulate_unit_price(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
        ]);

        $product = $this->product($company);

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
            5000,
        )->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_zero_or_negative_manual_price_is_rejected(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.cambiar_precio',
        ]);

        $product = $this->product($company);

        foreach ([0, -1] as $price) {
            $this->checkout(
                $user,
                $company,
                $branch,
                $cash,
                [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => $price,
                ]],
                5000,
            )->assertUnprocessable();
        }

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_line_discount_is_calculated_over_manual_price(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
            'pos.cambiar_precio',
        ]);

        $product = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 13,
        ]);

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 800,
                'discount' => 10,
                'discount_type' => 'percentage',
            ]],
            814,
        )->assertOk();

        $sale = Sale::with('items')->firstOrFail();
        $item = $sale->items->first();

        $this->assertSame('800.0000', $item->unit_price);
        $this->assertSame('80.0000', $item->discount_total);
        $this->assertSame('720.0000', $item->subtotal);
        $this->assertSame('93.6000', $item->tax_total);
        $this->assertSame('813.6000', $item->total);
        $this->assertSame('814.0000', $sale->total);
    }

    public function test_line_and_general_discounts_combine_before_tax(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
        ]);

        $product = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 13,
        ]);

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
                'discount' => 100,
                'discount_type' => 'fixed',
            ]],
            904,
            [
                'discount_total' => 100,
                'discount_total_type' => 'fixed',
            ],
        )->assertOk();

        $sale = Sale::with('items')->firstOrFail();
        $item = $sale->items->first();

        $this->assertSame('200.0000', $sale->discount_total);
        $this->assertSame('800.0000', $sale->subtotal);
        $this->assertSame('104.0000', $sale->tax_total);
        $this->assertSame('904.0000', $sale->total);

        $this->assertSame('200.0000', $item->discount_total);
        $this->assertSame('800.0000', $item->subtotal);
        $this->assertSame('104.0000', $item->tax_total);
    }

    public function test_general_discount_is_distributed_between_different_tax_rates(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
        ]);

        $product13 = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 13,
        ]);

        $product0 = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 0,
        ]);

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [
                [
                    'product_id' => $product13->id,
                    'quantity' => 1,
                ],
                [
                    'product_id' => $product0->id,
                    'quantity' => 1,
                ],
            ],
            1917,
            [
                'discount_total' => 200,
                'discount_total_type' => 'fixed',
            ],
        )->assertOk();

        $sale = Sale::with('items')->firstOrFail();
        $items = $sale->items->keyBy('product_id');

        $this->assertSame('200.0000', $sale->discount_total);
        $this->assertSame('1800.0000', $sale->subtotal);
        $this->assertSame('117.0000', $sale->tax_total);
        $this->assertSame('1917.0000', $sale->total);

        $this->assertSame('100.0000', $items[$product13->id]->discount_total);
        $this->assertSame('900.0000', $items[$product13->id]->subtotal);
        $this->assertSame('117.0000', $items[$product13->id]->tax_total);

        $this->assertSame('100.0000', $items[$product0->id]->discount_total);
        $this->assertSame('900.0000', $items[$product0->id]->subtotal);
        $this->assertSame('0.0000', $items[$product0->id]->tax_total);
    }

    public function test_general_discount_above_available_base_is_rejected(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
        ]);

        $product = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 0,
        ]);

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
            5000,
            [
                'discount_total' => 1001,
                'discount_total_type' => 'fixed',
            ],
        )->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_duplicate_product_with_adjustment_is_rejected(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
        ]);

        $product = $this->product($company);

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'discount' => 10,
                    'discount_type' => 'fixed',
                ],
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
            5000,
        )->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_idempotency_keeps_single_sale_and_single_inventory_debit_with_discount(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
        ]);

        $product = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 0,
            'track_inventory' => true,
        ]);

        $this->stock($branch, $product, 5);

        $token = (string) Str::uuid();

        $items = [[
            'product_id' => $product->id,
            'quantity' => 1,
            'discount' => 100,
            'discount_type' => 'fixed',
        ]];

        $first = $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            $items,
            900,
            [],
            $token,
        )->assertOk();

        $second = $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            $items,
            900,
            [],
            $token,
        )
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(
            $first->json('sale_id'),
            $second->json('sale_id'),
        );

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseCount('inventory_movements', 1);

        $this->assertEquals(
            4,
            DB::table('branch_product')
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->value('stock'),
        );
    }

    public function test_same_token_with_changed_discount_conflicts(): void
    {
        [$company, $branch, $user, $cash] = $this->context([
            'pos.acceder',
            'ventas.crear',
            'pos.aplicar_descuento',
        ]);

        $product = $this->product($company, [
            'sale_price' => 1000,
            'tax_rate' => 0,
        ]);

        $token = (string) Str::uuid();

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
                'discount' => 100,
                'discount_type' => 'fixed',
            ]],
            900,
            [],
            $token,
        )->assertOk();

        $this->checkout(
            $user,
            $company,
            $branch,
            $cash,
            [[
                'product_id' => $product->id,
                'quantity' => 1,
                'discount' => 200,
                'discount_type' => 'fixed',
            ]],
            800,
            [],
            $token,
        )->assertConflict();

        $this->assertDatabaseCount('sales', 1);
    }
            private function context(array $permissions): array
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

        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol POS '.uniqid(),
            'is_active' => true,
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                [
                    'label' => $name,
                    'module' => 'POS',
                    'is_active' => true,
                ],
            );

            $role->permissions()->syncWithoutDetaching(
                $permission,
            );
        }

        $user->companies()->attach(
            $company->id,
            ['role_id' => $role->id],
        );

        $user->branches()->attach(
            $branch->id,
        );

        $cash = PaymentMethod::create([
            'company_id' => $company->id,
            'code' => 'cash-'.uniqid(),
            'name' => 'Efectivo',
            'type' => 'cash',
            'is_active' => true,
            'allows_change' => true,
        ]);

        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CAJA-'.uniqid(), 'name' => 'Caja', 'is_active' => true]);
        CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'CAJA-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'opening_amount' => 0, 'opened_at' => now()]);

        return [
            $company,
            $branch,
            $user,
            $cash,
        ];
    }

    private function product(
        Company $company,
        array $attributes = [],
    ): Product {
        $suffix = uniqid();

        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoría '.$suffix,
            'slug' => 'categoria-'.$suffix,
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Unidad',
            'abbreviation' => 'U',
            'slug' => 'u-'.$suffix,
            'allows_decimals' => false,
            'is_active' => true,
        ]);

        return Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix,
            'internal_code' => 'P-'.$suffix,
            'cost' => 500,
            'sale_price' => 5000,
            'stock' => 123,
            'tax_rate' => 0,
            'track_inventory' => false,
            'is_active' => true,
        ], $attributes));
    }

    private function stock(
        Branch $branch,
        Product $product,
        float $stock,
    ): void {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => $stock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function checkout(
        User $user,
        Company $company,
        Branch $branch,
        PaymentMethod $method,
        array $items,
        int|float $received,
        array $extra = [],
        ?string $token = null,
    ) {
        $total = round(
            collect($items)->sum(function ($item) use ($extra) {
                $product = Product::findOrFail(
                    $item['product_id'],
                );

                $unitPrice = isset($item['unit_price'])
                    ? (float) $item['unit_price']
                    : (float) $product->sale_price;

                $gross = $unitPrice
                    * (float) $item['quantity'];

                $lineDiscount = 0.0;

                if (isset($item['discount'])) {
                    if (
                        ($item['discount_type'] ?? 'fixed')
                        === 'percentage'
                    ) {
                        $lineDiscount =
                            $gross
                            * ((float) $item['discount'] / 100);
                    } else {
                        $lineDiscount =
                            (float) $item['discount'];
                    }
                }

                return max(
                    0,
                    $gross - $lineDiscount,
                );
            }),
            4,
        );

        if (
            isset($extra['discount_total'])
            && $total > 0
        ) {
            if (
                ($extra['discount_total_type'] ?? 'fixed')
                === 'percentage'
            ) {
                $total -=
                    $total
                    * ((float) $extra['discount_total'] / 100);
            } else {
                $total -=
                    (float) $extra['discount_total'];
            }
        }

        $tax = collect($items)->sum(function ($item) use ($extra, $items) {
            $product = Product::findOrFail(
                $item['product_id'],
            );

            $unitPrice = isset($item['unit_price'])
                ? (float) $item['unit_price']
                : (float) $product->sale_price;

            $gross =
                $unitPrice
                * (float) $item['quantity'];

            $lineDiscount = 0.0;

            if (isset($item['discount'])) {
                $lineDiscount =
                    ($item['discount_type'] ?? 'fixed')
                    === 'percentage'
                        ? $gross
                            * ((float) $item['discount'] / 100)
                        : (float) $item['discount'];
            }

            $lineBase = max(
                0,
                $gross - $lineDiscount,
            );

            $allBase = collect($items)->sum(
                function ($otherItem) {
                    $otherProduct = Product::findOrFail(
                        $otherItem['product_id'],
                    );

                    $otherPrice =
                        isset($otherItem['unit_price'])
                            ? (float) $otherItem['unit_price']
                            : (float) $otherProduct->sale_price;

                    $otherGross =
                        $otherPrice
                        * (float) $otherItem['quantity'];

                    $otherDiscount = 0.0;

                    if (isset($otherItem['discount'])) {
                        $otherDiscount =
                            ($otherItem['discount_type'] ?? 'fixed')
                            === 'percentage'
                                ? $otherGross
                                    * ((float) $otherItem['discount'] / 100)
                                : (float) $otherItem['discount'];
                    }

                    return max(
                        0,
                        $otherGross - $otherDiscount,
                    );
                },
            );

            $general = 0.0;

            if (
                isset($extra['discount_total'])
                && $allBase > 0
            ) {
                $generalAmount =
                    ($extra['discount_total_type'] ?? 'fixed')
                    === 'percentage'
                        ? $allBase
                            * ((float) $extra['discount_total'] / 100)
                        : (float) $extra['discount_total'];

                $general =
                    $generalAmount
                    * ($lineBase / $allBase);
            }

            $taxable = max(
                0,
                $lineBase - $general,
            );

            return $taxable
                * ((float) ($product->tax_rate ?? 0) / 100);
        });

        $paymentTotal = round(
            $total + $tax,
            0,
            PHP_ROUND_HALF_UP,
        );

        return $this
            ->actingAs($user)
            ->withSession(
                $this->activeSession(
                    $company,
                    $branch,
                ),
            )
            ->postJson(
                route('pos.checkout'),
                array_merge([
                    'checkout_token' =>
                        $token ?? (string) Str::uuid(),

                    'customer_id' => null,

                    'payments' => [[
                        'payment_method_id' => $method->id,
                        'amount' => $paymentTotal,
                        'received_amount' => max(
                            $received,
                            $paymentTotal,
                        ),
                        'reference' => null,
                    ]],

                    'items' => $items,
                ], $extra),
            );
    }

    private function activeSession(
        Company $company,
        Branch $branch,
    ): array {
        return [
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ];
    }
}
