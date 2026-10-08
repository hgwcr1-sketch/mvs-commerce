<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cambio aprobado: cartera inicial POR COBRADOR (company + collector_user_id),
 * con revisión del prestamista, fotos de apertura inmutables y solicitudes de
 * corrección append-only. Nada se destruye.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_initial_portfolio_migrations', function (Blueprint $table) {
            $table->dropUnique(['company_id']);
            $table->foreignId('collector_user_id')->nullable()->after('company_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->after('finalized_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('finalized_by_user_id');
            $table->unique(['company_id', 'collector_user_id']);
        });

        // Foto de apertura inmutable por cierre (append-only).
        Schema::create('cobros_initial_portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_id')->constrained('cobros_initial_portfolio_migrations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('captured_at');
            $table->json('payload');
            $table->timestamps();
        });

        // Corrección de datos iniciales: original preserved + aprobación auditable.
        Schema::create('cobros_initial_portfolio_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_id')->constrained('cobros_initial_portfolio_migrations')->restrictOnDelete();
            $table->foreignId('record_id')->constrained('cobros_initial_portfolio_records')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->string('field', 40);
            $table->string('original_value', 60);
            $table->string('requested_value', 60);
            $table->string('reason', 250);
            $table->string('status', 20)->default('pendiente');
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'cip_corrections_company_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_initial_portfolio_correction_requests');
        Schema::dropIfExists('cobros_initial_portfolio_snapshots');
        Schema::table('cobros_initial_portfolio_migrations', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'collector_user_id']);
            $table->dropColumn(['collector_user_id', 'reviewed_by_user_id', 'reviewed_at']);
            $table->unique(['company_id']);
        });
    }
};
