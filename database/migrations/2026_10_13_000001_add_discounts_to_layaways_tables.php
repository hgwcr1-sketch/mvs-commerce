<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migración aditiva: persistir descuentos en apartados e ítems.
     * Los registros históricos conservan 0 (no tuvieron descuentos).
     */
    public function up(): void
    {
        Schema::table('layaways', function (Blueprint $table) {
            $table->decimal('discount_total', 19, 4)->default(0)->after('total');
        });

        Schema::table('layaway_items', function (Blueprint $table) {
            $table->decimal('discount_total', 19, 4)->default(0)->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('layaway_items', fn (Blueprint $table) => $table->dropColumn('discount_total'));
        Schema::table('layaways', fn (Blueprint $table) => $table->dropColumn('discount_total'));
    }
};
