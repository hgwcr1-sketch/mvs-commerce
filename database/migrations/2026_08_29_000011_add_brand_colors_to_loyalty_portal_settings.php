<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_portal_settings', function (Blueprint $table) {
            $table->string('brand_primary_color', 7)->default('#0F172A')->after('welcome_message');
            $table->string('brand_accent_color', 7)->default('#F59E0B')->after('brand_primary_color');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_portal_settings', fn (Blueprint $table) => $table->dropColumn(['brand_primary_color', 'brand_accent_color']));
    }
};
