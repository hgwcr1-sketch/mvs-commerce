<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 6 (parcial) — configuración OPERATIVA de visitas/cobro del cobrador.
 *
 * Aditivo y nullable: NO toca AccountReceivable.due_date, saldos, mora, cuotas,
 * pagos ni condiciones del crédito. La fecha financiera y la fecha operativa de
 * visita se mantienen estrictamente separadas. Ruta sigue siendo CobrosRoute;
 * Branch no es autorización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_assignments', function (Blueprint $table) {
            // diario | semanal | quincenal | mensual (visitas, no cuotas).
            $table->string('visit_frequency', 10)->nullable()->after('assigned_at');
            // 0..6 (0 = domingo): requerido solo con frecuencia semanal.
            $table->unsignedTinyInteger('visit_weekday')->nullable()->after('visit_frequency');
            // 1..31: requerido solo con frecuencia mensual.
            $table->unsignedTinyInteger('visit_month_day')->nullable()->after('visit_weekday');
            // Próxima visita confirmada / fecha ancla explícita (quincenal sin asumir 1/15).
            $table->date('next_visit_date')->nullable()->after('visit_month_day');
        });
    }

    public function down(): void
    {
        Schema::table('cobros_assignments', function (Blueprint $table) {
            $table->dropColumn(['visit_frequency', 'visit_weekday', 'visit_month_day', 'next_visit_date']);
        });
    }
};
