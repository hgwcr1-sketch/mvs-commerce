<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_licenses', function (Blueprint $table): void {
            $table->string('maestro_license_id', 190)->nullable()->after('company_id');
            $table->string('maestro_license_version', 80)->nullable()->after('maestro_license_id');
            $table->string('maestro_license_modality', 80)->nullable()->after('maestro_license_version');
            $table->json('maestro_license_limits')->nullable()->after('maestro_license_modality');
            $table->dateTime('maestro_license_synced_at')->nullable()->after('maestro_license_limits');
            $table->unique(['company_id', 'maestro_license_id'], 'company_license_maestro_external_unique');
            $table->index('maestro_license_version', 'company_license_maestro_version_index');
        });
    }

    public function down(): void
    {
        Schema::table('company_licenses', function (Blueprint $table): void {
            $table->dropUnique('company_license_maestro_external_unique');
            $table->dropIndex('company_license_maestro_version_index');
            $table->dropColumn(['maestro_license_id', 'maestro_license_version', 'maestro_license_modality', 'maestro_license_limits', 'maestro_license_synced_at']);
        });
    }
};
