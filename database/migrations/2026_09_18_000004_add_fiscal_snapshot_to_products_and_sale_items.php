<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('fiscal_profile_id')->nullable()->after('tax_rate')->constrained('fiscal_profiles')->nullOnDelete();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('tax_code', 2)->nullable()->after('tax_rate');
            $table->string('tax_rate_code', 2)->nullable()->after('tax_code');
            $table->string('tax_treatment', 40)->nullable()->after('tax_rate_code');
            $table->string('fiscal_source', 255)->nullable()->after('tax_treatment');
            $table->string('fiscal_source_version', 100)->nullable()->after('fiscal_source');
            $table->json('fiscal_snapshot')->nullable()->after('fiscal_source_version');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['tax_code', 'tax_rate_code', 'tax_treatment', 'fiscal_source', 'fiscal_source_version', 'fiscal_snapshot']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiscal_profile_id');
        });
    }
};