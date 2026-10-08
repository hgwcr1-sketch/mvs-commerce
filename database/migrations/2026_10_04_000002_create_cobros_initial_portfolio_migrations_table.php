<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 5A — control del período de migración inicial ("Cargar mi cartera
 * actual"): onboarding extraordinario para cartera preexistente a CobrOS.
 *
 * Una fila por empresa. Estados: en_progreso -> finalizada (bloqueada).
 * La reapertura excepcional solo por Platform Admin/MVS y queda auditable
 * (quién, cuándo, motivo). No toca clientes, CxC ni datos financieros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_initial_portfolio_migrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('status', 20)->default('en_progreso');
            $table->timestamp('started_at')->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reopen_reason', 250)->nullable();
            $table->unsignedInteger('reopen_count')->default(0);
            $table->timestamps();

            $table->unique(['company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_initial_portfolio_migrations');
    }
};
