<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->decimal('quantity', 19, 4)->change();
            $table->decimal('from_previous_stock', 19, 4)->change();
            $table->decimal('from_new_stock', 19, 4)->change();
            $table->decimal('to_previous_stock', 19, 4)->change();
            $table->decimal('to_new_stock', 19, 4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->decimal('quantity', 14, 2)->change();
            $table->decimal('from_previous_stock', 14, 2)->change();
            $table->decimal('from_new_stock', 14, 2)->change();
            $table->decimal('to_previous_stock', 14, 2)->change();
            $table->decimal('to_new_stock', 14, 2)->change();
        });
    }
};
