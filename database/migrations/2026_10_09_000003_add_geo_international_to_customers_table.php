<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('geo_country_iso', 2)->nullable()->after('district_id');
            $table->unsignedBigInteger('geo_division_id')->nullable()->after('geo_country_iso');
            $table->index('geo_division_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['geo_division_id']);
            $table->dropColumn(['geo_country_iso', 'geo_division_id']);
        });
    }
};
