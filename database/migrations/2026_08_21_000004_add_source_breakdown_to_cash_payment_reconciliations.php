<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_payment_reconciliations', function (Blueprint $table) {
            $table->decimal('sales_amount', 19, 4)->default(0)->after('payment_method_type_snapshot');
            $table->decimal('receivables_amount', 19, 4)->default(0)->after('sales_amount');
            $table->decimal('layaways_amount', 19, 4)->default(0)->after('receivables_amount');
        });
    }

    public function down(): void
    {
        Schema::table('cash_payment_reconciliations', function (Blueprint $table) {
            $table->dropColumn(['sales_amount', 'receivables_amount', 'layaways_amount']);
        });
    }
};
