<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('provider', 50)->default('facturaencr');
            $table->string('document_type', 10)->comment('01=FE, 04=TE, 03=NC, 02=ND, etc.');
            $table->string('environment', 20)->default('sandbox')->comment('sandbox o production');
            $table->string('idempotency_key', 255)->nullable()->unique();
            $table->string('provider_document_id', 255)->nullable()->comment('documentId del proveedor');
            $table->string('clave', 50)->nullable()->comment('Clave de 50 dígitos de Hacienda');
            $table->string('consecutivo', 20)->nullable()->comment('Consecutivo del comprobante');
            $table->string('status', 30)->default('pending')->comment('pending, queued, signing, sent, polling, accepted, rejected, error');
            $table->string('last_error_code', 50)->nullable();
            $table->text('last_error_message')->nullable();
            $table->string('provider_request_id', 255)->nullable()->comment('ID de la petición al proveedor para trazabilidad');
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'sale_id']);
            $table->index(['provider', 'provider_document_id']);
            $table->index(['idempotency_key'], 'idx_electronic_documents_idempotency');

            $table->unique(['company_id', 'sale_id', 'document_type'], 'uq_electronic_documents_company_sale_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_documents');
    }
};