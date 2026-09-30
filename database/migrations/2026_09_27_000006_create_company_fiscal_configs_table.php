<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración fiscal por empresa (multiempresa, NO global .env).
 *
 * El .env conserva solo defaults técnicos. Cada empresa guarda su
 * proveedor, ambiente y credenciales cifradas (casts encrypted, jamás
 * plaintext). Panel Maestro sigue siendo la autoridad comercial
 * (fiscal_enabled/quota/overage); aquí solo operación del tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_fiscal_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 30)->default('facturaencr');
            $table->string('environment', 20)->default('sandbox');
            $table->text('provider_api_key')->nullable();
            $table->text('provider_api_secret')->nullable();
            $table->string('default_document', 10)->default('01');
            $table->boolean('auto_emit_enabled')->default(false);
            $table->boolean('notify_receptor_email')->default(false);
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_error_code', 50)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_fiscal_configs');
    }
};
