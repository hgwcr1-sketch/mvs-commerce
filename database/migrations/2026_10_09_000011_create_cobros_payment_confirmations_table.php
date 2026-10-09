<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOQUE 17A-2 f1 — confirmación de pago CobrOS (independiente del proveedor).
 *
 * Registra cada pago confirmado con transacción única, contrato, importe,
 * moneda, período y responsable. La activación es idempotente por
 * transaction_id. NUNCA se borran filas: vencidos/suspendidos solo cambian
 * de estado (sin destruir carteras ni datos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_payment_confirmations', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id', 120)->unique();
            $table->foreignId('cobros_billing_contract_id')->constrained('cobros_billing_contracts')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->decimal('amount', 19, 4);
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending'); // pending | confirmed | expired | suspended
            $table->string('provider', 30)->default('internal'); // internal | stripe | sinpe
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['cobros_billing_contract_id', 'period_year', 'period_month']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_payment_confirmations');
    }
};
