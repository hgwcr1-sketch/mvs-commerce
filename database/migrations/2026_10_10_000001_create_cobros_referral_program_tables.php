<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRE-19B-1 — base del programa de referidos y crecimiento CobrOS (V1).
 *
 * Estructuras mínimas, sin conexión a Stripe/SINPE ni a landing/onboarding:
 *  - users.referral_code: enlace personal de referido (?ref= propio).
 *  - cobros_referral_attributions: atribución permanente del alta a su
 *    referente comercial (una sola por usuario referido).
 *  - cobros_referral_bonus_credits: bonificaciones como SALDO A FAVOR
 *    (status=credit); nunca dinero desembolsado automáticamente. Unicidad
 *    doble: event_key único (beneficiario+alta+evento de pago) y
 *    (referred_user_id, source_confirmation_id) global para que una misma
 *    alta jamás reciba referido comercial Y crecimiento por el mismo pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique()->after('remember_token');
        });

        Schema::create('cobros_referral_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referrer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('code', 16);
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique('referred_user_id', 'cobros_referral_attr_unique_referred');
            $table->index(['referrer_user_id', 'created_at']);
        });

        Schema::create('cobros_referral_bonus_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('beneficiary_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 30); // referral_first_payment | growth_collaborator
            $table->foreignId('source_contract_id')->constrained('cobros_billing_contracts')->restrictOnDelete();
            $table->foreignId('source_confirmation_id')->constrained('cobros_payment_confirmations')->restrictOnDelete();
            $table->decimal('percent', 7, 4);
            $table->decimal('amount', 19, 4);
            $table->string('currency', 3);
            $table->string('status', 20)->default('credit'); // solo saldo a favor
            $table->string('event_key', 64);
            $table->timestamps();

            $table->unique('event_key', 'cobros_bonus_unique_event');
            $table->unique(
                ['referred_user_id', 'source_confirmation_id'],
                'cobros_bonus_unique_referred_payment'
            );
            $table->index(['beneficiary_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_referral_bonus_credits');
        Schema::dropIfExists('cobros_referral_attributions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });
    }
};
