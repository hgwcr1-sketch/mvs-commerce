<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_credit_renewal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('credit_id');
            $table->foreignId('account_receivable_id');
            $table->foreignId('collector_user_id');
            $table->decimal('new_capital', 14, 4);
            $table->decimal('previous_pending', 14, 4);
            $table->decimal('previous_principal', 14, 4);
            $table->decimal('previous_interest', 14, 4);
            $table->decimal('new_interest', 14, 4);
            $table->decimal('consolidated_total', 14, 4);
            $table->decimal('disbursement', 14, 4);
            $table->unsignedInteger('installment_count');
            $table->string('frequency');
            $table->date('first_due_date');
            $table->json('schedule_snapshot');
            $table->string('status')->default('pending'); // pending|approved
            $table->foreignId('requested_by');
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'credit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_credit_renewal_requests');
    }
};
