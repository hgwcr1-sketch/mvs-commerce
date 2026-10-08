<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOQUE 9A.2 — evidencia de aceptación firmada del préstamo.
 * Una fila por crédito (UNIQUE credit_id): firma privada, condiciones EXACTAS
 * aceptadas (snapshot) y SHA-256 para comprobar que no fueron alteradas.
 * Sin updated_at: la aceptación es inmutable una vez confirmada.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cobros_credit_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('credit_id')->unique()->constrained('cobros_credits')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('delivered_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('cobros_route_id')->constrained('cobros_routes')->restrictOnDelete();
            $table->timestamp('accepted_at');
            $table->string('signature_path');
            $table->string('reference', 20);
            $table->json('terms_snapshot');
            $table->char('terms_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_credit_acceptances');
    }
};
