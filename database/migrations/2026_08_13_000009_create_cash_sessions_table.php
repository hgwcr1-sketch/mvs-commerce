<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('cash_register_id')->constrained('cash_registers')->restrictOnDelete();
            $table->string('session_number', 50);
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('difference_authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('open');
            $table->string('open_guard', 20)->nullable();
            $table->char('currency_code', 3)->default('CRC');
            $table->decimal('opening_amount', 19, 4)->default(0);
            $table->decimal('expected_cash', 19, 4)->nullable();
            $table->decimal('counted_cash', 19, 4)->nullable();
            $table->decimal('difference_amount', 19, 4)->nullable();
            $table->decimal('tolerance_snapshot', 19, 4)->default(0);
            $table->boolean('accepts_usd_snapshot')->default(false);
            $table->decimal('usd_exchange_rate', 19, 4)->nullable();
            $table->foreignId('exchange_rate_entered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('opening_amount_usd', 19, 4)->default(0);
            $table->decimal('expected_cash_usd', 19, 4)->nullable();
            $table->decimal('counted_cash_usd', 19, 4)->nullable();
            $table->decimal('difference_amount_usd', 19, 4)->nullable();
            $table->boolean('blind_closing_snapshot')->default(true);
            $table->string('usd_change_policy_snapshot', 20)->default('crc_only');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('difference_authorized_at')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'session_number'], 'cash_sessions_company_number_unique');
            $table->unique(['cash_register_id', 'open_guard'], 'cash_sessions_register_open_guard_unique');
            $table->index(['company_id', 'branch_id', 'status'], 'cash_sessions_company_branch_status_index');
            $table->index(['opened_by', 'status'], 'cash_sessions_opened_by_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
