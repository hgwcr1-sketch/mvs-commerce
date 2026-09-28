<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->decimal('received_amount_usd', 19, 4)->nullable();
            $table->decimal('change_amount_usd', 19, 4)->nullable();
            $table->decimal('exchange_rate_snapshot', 19, 4)->nullable();
            $table->decimal('cash_effect_amount_usd', 19, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropColumn(['received_amount_usd', 'change_amount_usd', 'exchange_rate_snapshot', 'cash_effect_amount_usd']);
        });
    }
};
