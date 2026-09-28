<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price_a', 15, 2)
                ->nullable()
                ->after('special_price');

            $table->decimal('price_b', 15, 2)
                ->nullable()
                ->after('price_a');

            $table->decimal('price_c', 15, 2)
                ->nullable()
                ->after('price_b');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('price_level', 20)
                ->default('normal')
                ->after('credit_days');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('price_level');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'price_a',
                'price_b',
                'price_c',
            ]);
        });
    }
};