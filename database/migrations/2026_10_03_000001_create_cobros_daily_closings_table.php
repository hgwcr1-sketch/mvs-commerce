<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre diario CobrOS — el cobrador declara los totales del día por medio de
 * pago (efectivo, SINPE, datáfono, depósito), adjunta comprobantes, agrega
 * notas y envía el cierre al dueño. El sistema guarda junto al cierre un
 * snapshot inmutable de los totales REALES calculados desde los pagos del día
 * para que el dueño vea las diferencias sin recalcular sobre datos cambiantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_daily_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->date('business_date');

            // Totales declarados por el cobrador.
            $table->decimal('declared_cash', 19, 4)->default(0);
            $table->decimal('declared_sinpe', 19, 4)->default(0);
            $table->decimal('declared_card', 19, 4)->default(0);
            $table->decimal('declared_bank_transfer', 19, 4)->default(0);

            // Snapshot de totales reales calculados al enviar el cierre.
            $table->json('computed_totals');
            $table->unsignedInteger('payments_count')->default(0);

            $table->text('notes')->nullable();
            $table->string('evidence_deposit_path', 500)->nullable();
            $table->string('evidence_dataphone_path', 500)->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'collector_user_id', 'business_date'], 'cobros_closings_unique_day');
            $table->index(['company_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_daily_closings');
    }
};
