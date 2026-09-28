<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_ORDERS = 'legacy_commercial_orders';
    private const LEGACY_ITEMS = 'legacy_commercial_order_items';

    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('order_items')) {
            throw new RuntimeException('No existen las tablas de pedidos que deben convertirse.');
        }

        // En instalaciones nuevas, 000008 y 000009 ya crean directamente Pedido Interno V1.
        if (Schema::hasColumn('orders', 'number') && Schema::hasColumn('order_items', 'requested_quantity')) {
            return;
        }

        if (! Schema::hasColumn('orders', 'order_number') || ! Schema::hasColumn('order_items', 'quantity')) {
            throw new RuntimeException('La estructura existente de pedidos no coincide con el esquema comercial esperado.');
        }

        $unsupportedStatuses = DB::table('orders')
            ->whereNotIn('status', ['pending', 'confirmed', 'completed', 'cancelled'])
            ->distinct()
            ->pluck('status');

        if ($unsupportedStatuses->isNotEmpty()) {
            throw new RuntimeException('Hay estados de pedidos comerciales que no se pueden transformar de forma segura: '.$unsupportedStatuses->implode(', '));
        }

        $duplicateNumbers = DB::table('orders')
            ->select('company_id', 'order_number')
            ->groupBy('company_id', 'order_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateNumbers) {
            throw new RuntimeException('Existen números de pedido duplicados dentro de una empresa.');
        }

        $this->createLegacyArchive();
        $this->archiveLegacyData();

        Schema::disableForeignKeyConstraints();
        try {
            Schema::drop('order_items');
            Schema::drop('orders');
            $this->createInternalOrders();
            $this->createInternalOrderItems();
            $this->restoreAsInternalOrders();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        // La migración fue un no-op en una instalación creada ya con el esquema V1.
        if (! Schema::hasTable(self::LEGACY_ORDERS) || ! Schema::hasTable(self::LEGACY_ITEMS)) {
            return;
        }

        if (DB::table('orders')->count() !== DB::table(self::LEGACY_ORDERS)->count()
            || DB::table('order_items')->count() !== DB::table(self::LEGACY_ITEMS)->count()) {
            throw new RuntimeException('No se puede revertir la transición sin perder pedidos internos creados posteriormente.');
        }

        Schema::disableForeignKeyConstraints();
        try {
            Schema::drop('order_items');
            Schema::drop('orders');
            $this->createCommercialOrders();
            $this->createCommercialOrderItems();
            $this->restoreLegacyData();
            Schema::drop(self::LEGACY_ITEMS);
            Schema::drop(self::LEGACY_ORDERS);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function createLegacyArchive(): void
    {
        Schema::create(self::LEGACY_ORDERS, function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('order_number', 50);
            $table->string('status', 30);
            $table->string('currency_code', 3)->nullable();
            $table->decimal('subtotal', 19, 4)->nullable();
            $table->decimal('discount_total', 19, 4)->nullable();
            $table->decimal('tax_total', 19, 4)->nullable();
            $table->decimal('total', 19, 4)->nullable();
            $table->date('requested_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_sale_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create(self::LEGACY_ITEMS, function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('description', 255);
            $table->string('internal_code', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('cabys_code', 30)->nullable();
            $table->string('unit_code', 20);
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('gross_total', 19, 4)->nullable();
            $table->decimal('discount_amount', 19, 4)->nullable();
            $table->decimal('subtotal', 19, 4)->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->decimal('tax_amount', 19, 4)->nullable();
            $table->decimal('total', 19, 4)->nullable();
            $table->decimal('unit_cost', 19, 4)->nullable();
            $table->timestamps();
        });
    }

    private function archiveLegacyData(): void
    {
        $orderColumns = ['id', 'company_id', 'branch_id', 'user_id', 'customer_id', 'order_number', 'status', 'currency_code', 'subtotal', 'discount_total', 'tax_total', 'total', 'requested_date', 'notes', 'confirmed_at', 'confirmed_by', 'completed_at', 'completed_sale_id', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'created_at', 'updated_at'];
        $itemColumns = ['id', 'order_id', 'product_id', 'description', 'internal_code', 'barcode', 'cabys_code', 'unit_code', 'quantity', 'unit_price', 'gross_total', 'discount_amount', 'subtotal', 'tax_rate', 'tax_amount', 'total', 'unit_cost', 'created_at', 'updated_at'];

        DB::table(self::LEGACY_ORDERS)->insertUsing($orderColumns, DB::table('orders')->select($orderColumns));
        DB::table(self::LEGACY_ITEMS)->insertUsing($itemColumns, DB::table('order_items')->select($itemColumns));
    }

    private function createInternalOrders(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('number', 50);
            $table->string('status', 30)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number'], 'orders_company_number_unique');
            $table->index(['company_id', 'branch_id', 'status'], 'orders_company_branch_status_index');
        });
    }

    private function createInternalOrderItems(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->string('internal_code', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('unit_code', 20);
            $table->boolean('allows_decimals_snapshot')->nullable();
            $table->decimal('requested_quantity', 19, 4);
            $table->decimal('stock_snapshot', 19, 4)->nullable();
            $table->decimal('sale_price_snapshot', 19, 4);
            $table->decimal('cost_snapshot', 19, 4)->nullable();
            $table->decimal('last_cost_snapshot', 19, 4)->nullable();
            $table->decimal('approved_quantity', 19, 4)->default(0);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_status', 30)->default('pending');
            $table->text('request_note')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'product_id'], 'order_items_order_product_index');
            $table->index(['supplier_id', 'item_status'], 'order_items_supplier_status_index');
        });
    }

    private function restoreAsInternalOrders(): void
    {
        DB::table(self::LEGACY_ORDERS)->orderBy('id')->each(function ($legacy) {
            DB::table('orders')->insert([
                'id' => $legacy->id,
                'company_id' => $legacy->company_id,
                'branch_id' => $legacy->branch_id,
                'user_id' => $legacy->user_id,
                'number' => $legacy->order_number,
                'status' => $legacy->status === 'confirmed' ? 'approved' : $legacy->status,
                'notes' => $legacy->notes,
                'reviewed_at' => $legacy->confirmed_at,
                'reviewed_by' => $legacy->confirmed_by,
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
                'cancelled_at' => $legacy->cancelled_at,
                'cancelled_by' => $legacy->cancelled_by,
                'cancellation_reason' => $legacy->cancellation_reason,
                'created_at' => $legacy->created_at,
                'updated_at' => $legacy->updated_at,
            ]);
        });

        DB::table(self::LEGACY_ITEMS)
            ->leftJoin('products', 'products.id', '=', self::LEGACY_ITEMS.'.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->select(self::LEGACY_ITEMS.'.*', 'units.allows_decimals')
            ->orderBy(self::LEGACY_ITEMS.'.id')
            ->each(function ($legacy) {
                DB::table('order_items')->insert([
                    'id' => $legacy->id,
                    'order_id' => $legacy->order_id,
                    'product_id' => $legacy->product_id,
                    'description' => $legacy->description,
                    'internal_code' => $legacy->internal_code,
                    'barcode' => $legacy->barcode,
                    'unit_code' => $legacy->unit_code,
                    'allows_decimals_snapshot' => $legacy->allows_decimals,
                    'requested_quantity' => $legacy->quantity,
                    'stock_snapshot' => null,
                    'sale_price_snapshot' => $legacy->unit_price,
                    'cost_snapshot' => $legacy->unit_cost,
                    'last_cost_snapshot' => null,
                    'approved_quantity' => 0,
                    'supplier_id' => null,
                    'item_status' => 'pending',
                    'request_note' => null,
                    'review_note' => null,
                    'created_at' => $legacy->created_at,
                    'updated_at' => $legacy->updated_at,
                ]);
            });
    }

    private function createCommercialOrders(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number', 50);
            $table->string('status', 30)->default('pending');
            $table->string('currency_code', 3)->nullable();
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->date('requested_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamps();
        });
    }

    private function createCommercialOrderItems(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->string('internal_code', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('cabys_code', 30)->nullable();
            $table->string('unit_code', 20);
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('gross_total', 19, 4)->default(0);
            $table->decimal('discount_amount', 19, 4)->default(0);
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->decimal('unit_cost', 19, 4)->nullable();
            $table->timestamps();
        });
    }

    private function restoreLegacyData(): void
    {
        DB::table('orders')->insertUsing(
            ['id', 'company_id', 'branch_id', 'user_id', 'customer_id', 'order_number', 'status', 'currency_code', 'subtotal', 'discount_total', 'tax_total', 'total', 'requested_date', 'notes', 'confirmed_at', 'confirmed_by', 'completed_at', 'completed_sale_id', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'created_at', 'updated_at'],
            DB::table(self::LEGACY_ORDERS)->select(['id', 'company_id', 'branch_id', 'user_id', 'customer_id', 'order_number', 'status', 'currency_code', 'subtotal', 'discount_total', 'tax_total', 'total', 'requested_date', 'notes', 'confirmed_at', 'confirmed_by', 'completed_at', 'completed_sale_id', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'created_at', 'updated_at'])
        );
        DB::table('order_items')->insertUsing(
            ['id', 'order_id', 'product_id', 'description', 'internal_code', 'barcode', 'cabys_code', 'unit_code', 'quantity', 'unit_price', 'gross_total', 'discount_amount', 'subtotal', 'tax_rate', 'tax_amount', 'total', 'unit_cost', 'created_at', 'updated_at'],
            DB::table(self::LEGACY_ITEMS)->select(['id', 'order_id', 'product_id', 'description', 'internal_code', 'barcode', 'cabys_code', 'unit_code', 'quantity', 'unit_price', 'gross_total', 'discount_amount', 'subtotal', 'tax_rate', 'tax_amount', 'total', 'unit_cost', 'created_at', 'updated_at'])
        );
    }
};
