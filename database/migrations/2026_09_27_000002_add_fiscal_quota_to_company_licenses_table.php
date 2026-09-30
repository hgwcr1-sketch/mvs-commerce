<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_licenses', function (Blueprint $table) {
            $table->boolean('fiscal_enabled')->default(false)->after('branch_limit');
            $table->unsignedInteger('fiscal_monthly_quota')->nullable()->after('fiscal_enabled');
            $table->boolean('fiscal_overage_enabled')->default(false)->after('fiscal_monthly_quota');
            $table->decimal('fiscal_overage_unit_price', 19, 4)->nullable()->after('fiscal_overage_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_licenses', function (Blueprint $table) {
            $table->dropColumn(['fiscal_enabled', 'fiscal_monthly_quota', 'fiscal_overage_enabled', 'fiscal_overage_unit_price']);
        });
    }
};
