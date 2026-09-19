<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounts_receivable_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_receivable_id')
                ->constrained('accounts_receivable')
                ->restrictOnDelete();
            $table->foreignId('credit_note_id')
                ->nullable()
                ->constrained('credit_notes')
                ->cascadeOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 19, 4);
            $table->decimal('balance_before', 19, 4);
            $table->decimal('balance_after', 19, 4);
            $table->string('reason', 255);
            $table->string('status', 20)->default('active');
            $table->foreignId('voided_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->string('idempotency_key', 100);
            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['company_id', 'idempotency_key'],
                'ar_adjustments_company_key_unique'
            );

            $table->index(
                ['company_id', 'account_receivable_id'],
                'ar_adjustments_company_ar_index'
            );

            $table->index(
                ['company_id', 'credit_note_id'],
                'ar_adjustments_company_cn_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_receivable_adjustments');
    }
};
