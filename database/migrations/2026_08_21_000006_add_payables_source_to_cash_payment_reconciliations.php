<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_payment_reconciliations', function (Blueprint $table) {
            $table->decimal('payables_amount', 19, 4)->default(0)->after('layaways_amount');
        });
    }

    public function down(): void
    {
        Schema::table('cash_payment_reconciliations', function (Blueprint $table) {
            $table->dropColumn('payables_amount');
        });
    }
};
