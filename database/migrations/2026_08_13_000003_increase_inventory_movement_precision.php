<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('quantity', 19, 4)->change();
            $table->decimal('previous_stock', 19, 4)->default(0)->change();
            $table->decimal('new_stock', 19, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('quantity', 14, 2)->change();
            $table->decimal('previous_stock', 14, 2)->default(0)->change();
            $table->decimal('new_stock', 14, 2)->default(0)->change();
        });
    }
};
