<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cobros_maestro_identity_bindings', function (Blueprint $table): void {
            $table->string('maestro_company_id', 190)->nullable()->after('company_id');
            $table->index(['company_id', 'maestro_company_id'], 'cobros_maestro_identity_company_map_index');
        });
        Schema::create('cobros_maestro_company_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('maestro_company_id', 190);
            $table->string('maestro_application_id', 190);
            $table->string('maestro_installation_id', 190);
            $table->string('source_system', 40)->default('cobros');
            $table->string('status', 20)->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'maestro_application_id', 'maestro_installation_id', 'source_system'], 'cobros_maestro_company_local_unique');
            $table->unique(['maestro_company_id', 'maestro_application_id', 'maestro_installation_id', 'source_system'], 'cobros_maestro_company_external_unique');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('cobros_maestro_company_bindings');
        Schema::table('cobros_maestro_identity_bindings', function (Blueprint $table): void {
            $table->dropIndex('cobros_maestro_identity_company_map_index'); $table->dropColumn('maestro_company_id');
        });
    }
};
