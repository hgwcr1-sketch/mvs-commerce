<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('cobros_renewal_late_fee_obligations', function (Blueprint $table): void {
            $table->id(); $table->foreignId('company_id')->constrained(); $table->foreignId('source_credit_id')->constrained('cobros_credits'); $table->unsignedBigInteger('renewal_id')->index();
            $table->decimal('original_amount', 19, 4); $table->decimal('applied_amount', 19, 4)->default(0); $table->string('status', 16)->default('pending'); $table->dateTime('frozen_at'); $table->timestamps();
            $table->unique(['source_credit_id', 'renewal_id']); $table->index(['company_id', 'status']);
        });
        Schema::create('cobros_renewal_late_fee_payments', function (Blueprint $table): void {
            $table->id(); $table->foreignId('obligation_id')->constrained('cobros_renewal_late_fee_obligations')->cascadeOnDelete(); $table->foreignId('cobros_receipt_id')->constrained('cobros_receipts'); $table->decimal('amount', 19, 4); $table->timestamps();
            $table->unique('cobros_receipt_id');
        });
    }
    public function down(): void { Schema::dropIfExists('cobros_renewal_late_fee_payments'); Schema::dropIfExists('cobros_renewal_late_fee_obligations'); }
};
