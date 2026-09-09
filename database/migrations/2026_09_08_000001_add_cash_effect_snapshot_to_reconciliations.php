<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_payment_reconciliations', function (Blueprint $table) {
            $table->boolean('affects_cash_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_payment_reconciliations', function (Blueprint $table) {
            $table->dropColumn('affects_cash_snapshot');
        });
    }
};
