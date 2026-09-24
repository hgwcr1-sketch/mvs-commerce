<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('account_receivable_id')->nullable()->constrained('accounts_receivable')->nullOnDelete();
            $table->date('scheduled_for');
            $table->date('visited_at')->nullable();
            $table->string('status', 20)->default('pendiente');
            $table->date('promise_date')->nullable();
            $table->decimal('promise_amount', 19, 4)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('rescheduled_from_visit_id')->nullable()->constrained('cobros_visits')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'collector_user_id', 'scheduled_for', 'status'], 'cobros_visits_agenda_index');
            $table->index(['company_id', 'customer_id'], 'cobros_visits_customer_index');
        });

        Schema::create('cobros_visit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('visit_id')->constrained('cobros_visits')->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'visit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_visit_logs');
        Schema::dropIfExists('cobros_visits');
    }
};
