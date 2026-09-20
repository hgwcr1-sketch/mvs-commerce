<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R01 MVS RouteOS — Bitácora de auditoría reutilizable.
 *
 * Registra acciones sensibles con actor, empresa, sucursal (cuando aplique),
 * entidad, valores anteriores/nuevos y metadata. No se almacena información
 * innecesariamente sensible (contraseñas, tokens, datos de pago).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routeos_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('entity_type', 120)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['company_id', 'action', 'occurred_at']);
            $table->index(['company_id', 'branch_id', 'action']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routeos_audit_logs');
    }
};
