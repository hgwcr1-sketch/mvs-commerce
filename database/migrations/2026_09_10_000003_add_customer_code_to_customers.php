<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_code', 20)->nullable()->after('id');

            $table->unique(
                ['company_id', 'customer_code'],
                'customers_company_customer_code_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_company_customer_code_unique');
            $table->dropColumn('customer_code');
        });
    }
};