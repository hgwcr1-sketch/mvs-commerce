<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRE-19B-4A — reservas idempotentes de saldo a favor de referidos.
 *
 * Una fila = un bono comprometido hacia una mensualidad/período concreto.
 * El bono NUNCA cambia de estado aquí (sigue status=credit hasta la
 * liquidación legítima de 4B); la reserva solo evita doble descuento
 * restando del saldo disponible. Unicidad dura: un bono se reserva una
 * sola vez en todo el sistema (bonus_credit_id único).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_referral_credit_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('beneficiary_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('cobros_billing_contract_id')->constrained('cobros_billing_contracts')->restrictOnDelete();
            $table->foreignId('bonus_credit_id')->constrained('cobros_referral_bonus_credits')->restrictOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->decimal('amount', 19, 4);
            $table->string('currency', 3);
            $table->string('status', 20)->default('reserved'); // reserved | settled
            $table->timestamps();

            $table->unique('bonus_credit_id', 'cobros_ref_credit_unique_bonus');
            $table->unique(
                ['cobros_billing_contract_id', 'period_year', 'period_month', 'beneficiary_user_id', 'bonus_credit_id'],
                'cobros_ref_credit_unique_period_bonus'
            );
            $table->index(['beneficiary_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_referral_credit_reservations');
    }
};
