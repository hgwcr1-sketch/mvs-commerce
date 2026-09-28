<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['company_id', 'customer_id', 'status', 'completed_at'], 'sales_portal_history_index');
        });

        Schema::table('loyalty_movements', function (Blueprint $table) {
            $table->index(['company_id', 'customer_id', 'effective_at'], 'loyalty_movements_portal_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['company_id', 'is_active', 'special_price'], 'products_portal_offers_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropIndex('sales_portal_history_index'));
        Schema::table('loyalty_movements', fn (Blueprint $table) => $table->dropIndex('loyalty_movements_portal_index'));
        Schema::table('products', fn (Blueprint $table) => $table->dropIndex('products_portal_offers_index'));
    }
};
