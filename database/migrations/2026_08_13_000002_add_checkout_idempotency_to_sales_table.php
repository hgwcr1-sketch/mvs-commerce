<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->uuid('checkout_token')->nullable()->after('customer_id');
            $table->char('request_fingerprint', 64)->nullable()->after('checkout_token');

            $table->unique(
                ['company_id', 'checkout_token'],
                'sales_company_checkout_token_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_company_checkout_token_unique');
            $table->dropColumn(['checkout_token', 'request_fingerprint']);
        });
    }
};
