<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOQUE política de AUTORIDAD de préstamos (company + cobrador).
 *
 * owner_required (default SEGURO): el dueño es la autoridad económica.
 * collector_authorized: ese cobrador autoriza sus propios préstamos.
 *
 * Aditiva: no toca créditos/CxC existentes. El valor aplicado a cada
 * decisión se congela además en terms_snapshot al aprobar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_collector_authority_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->string('authority_policy', 30)->default('owner_required');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'collector_user_id']);
            $table->index(['company_id', 'authority_policy']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_collector_authority_policies');
    }
};
