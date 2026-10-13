<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_modules', function (Blueprint $table) {
            $table->string('license_mode', 20)->nullable()->after('is_enabled');
            $table->unsignedSmallInteger('collector_limit')->nullable()->after('license_mode');
            $table->boolean('admin_license_charged')->nullable()->after('collector_limit');
        });
    }

    public function down(): void
    {
        Schema::table('company_modules', function (Blueprint $table) {
            $table->dropColumn(['license_mode', 'collector_limit', 'admin_license_charged']);
        });
    }
};
