<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rotación segura de conexión fiscal (verificar antes de activar) +
 * auditoría de cambios. La conexión vigente jamás se destruye por editar
 * un formulario: lo nuevo queda pendiente, se verifica y solo entonces
 * se activa. Sin secretos en la auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->string('pending_provider', 30)->nullable()->after('provider_api_secret');
            $table->string('pending_environment', 20)->nullable()->after('pending_provider');
            $table->text('pending_api_key')->nullable()->after('pending_environment');
            $table->text('pending_api_secret')->nullable()->after('pending_api_key');
        });

        Schema::create('fiscal_config_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20)->default('sandbox');
            $table->string('change_type', 40);
            $table->string('result', 20)->default('ok');
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_config_audits');

        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropColumn(['pending_provider', 'pending_environment', 'pending_api_key', 'pending_api_secret']);
        });
    }
};
