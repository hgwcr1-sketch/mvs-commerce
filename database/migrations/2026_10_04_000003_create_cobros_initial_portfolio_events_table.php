<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX 5A — auditoría no destructiva de "Cargar mi cartera actual".
 *
 * Eventos APPEND-ONLY de cada ciclo: abierta → cerrada → reabierta → cerrada…
 * Cada evento conserva actor, fecha y motivo. La tabla de estado queda como
 * proyección; los eventos jamás se borran ni se sobrescriben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_initial_portfolio_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_id')->constrained('cobros_initial_portfolio_migrations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('type', 20); // abierta | cerrada | reabierta
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 250)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['company_id', 'occurred_at', 'id'], 'cip_events_company_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_initial_portfolio_events');
    }
};
