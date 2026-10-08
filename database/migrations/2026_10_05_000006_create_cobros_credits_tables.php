<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOQUE 9 — crédito CobrOS como entidad raíz independiente de la CxC Core.
 * - cobros_credits: UN crédito por solicitud (UNIQUE credit_request_id = idempotencia),
 *   términos congelados en terms_snapshot al aprobar; status approved|disbursed.
 * - cobros_credit_installments: calendario de cuotas generado SOLO al desembolsar
 *   (suma exacta = total_due; la última cuota absorbe el residuo de redondeo).
 * La mora NO capitaliza: applied_amount/estado registran avance; mora va en 9B.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cobros_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('credit_request_id')->unique()->constrained('cobros_credit_requests')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('cobros_route_id')->constrained('cobros_routes')->restrictOnDelete();
            $table->foreignId('account_receivable_id')->nullable()->unique()->constrained('accounts_receivable')->restrictOnDelete();
            $table->decimal('principal', 19, 4);
            $table->string('interest_type', 20);
            $table->decimal('interest_value', 19, 4)->default(0);
            $table->decimal('total_due', 19, 4);
            $table->unsignedInteger('installment_count');
            $table->string('frequency', 20);
            $table->date('disbursement_date')->nullable();
            $table->date('first_due_date');
            $table->string('late_fee_type', 20)->default('none');
            $table->decimal('late_fee_value', 19, 4)->default(0);
            $table->unsignedInteger('late_fee_grace_days')->default(0);
            $table->string('status', 20)->default('approved');
            $table->json('terms_snapshot');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('cobros_credit_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_id')->constrained('cobros_credits')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->date('due_date');
            $table->decimal('contractual_amount', 19, 4);
            $table->decimal('applied_amount', 19, 4)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['credit_id', 'number']);
            $table->index(['credit_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_credit_installments');
        Schema::dropIfExists('cobros_credits');
    }
};
