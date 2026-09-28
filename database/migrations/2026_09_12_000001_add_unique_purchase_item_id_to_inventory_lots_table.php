<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->unique('purchase_item_id', 'inventory_lots_purchase_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->dropUnique('inventory_lots_purchase_item_unique');
        });
    }
};
