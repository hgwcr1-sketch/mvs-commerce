<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_document_mail_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('electronic_document_id')->constrained('electronic_documents')->cascadeOnDelete();
            $table->string('recipient', 150)->nullable()->comment('Correo del receptor; null cuando no existe');
            $table->enum('delivery_type', ['automatic', 'manual'])->default('automatic');
            $table->enum('status', ['pending', 'processing', 'sent', 'failed', 'skipped'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('message', 255)->nullable()->comment('Resumen legible del resultado');
            $table->json('attachments')->nullable()->comment('Nombres exactos de los archivos adjuntos enviados');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('null = sistema (automático)');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['electronic_document_id', 'delivery_type'],
                'edmd_doc_type_unique'
            );
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_document_mail_deliveries');
    }
};
