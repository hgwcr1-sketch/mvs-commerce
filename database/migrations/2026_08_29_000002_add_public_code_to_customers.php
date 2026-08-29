<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('public_code', 12)->nullable()->after('is_active');
        });
        // Unique per company, nullable ignores nulls in SQLite/MySQL
        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['company_id', 'public_code'], 'customers_company_public_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_company_public_code_unique');
            $table->dropColumn('public_code');
        });
    }
};
