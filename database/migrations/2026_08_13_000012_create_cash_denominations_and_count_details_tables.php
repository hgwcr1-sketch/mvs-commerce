<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_denominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->decimal('value', 19, 4);
            $table->string('label', 50);
            $table->string('type', 20);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'currency_code', 'value'], 'cash_denominations_company_currency_value_unique');
            $table->index(['company_id', 'currency_code', 'is_active', 'sort_order'], 'cash_denominations_company_currency_active_sort_index');
        });

        Schema::create('cash_count_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->cascadeOnDelete();
            $table->foreignId('cash_denomination_id')->constrained('cash_denominations')->restrictOnDelete();
            $table->string('count_type', 20);
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('denomination_value', 19, 4);
            $table->decimal('total_amount', 19, 4);
            $table->foreignId('counted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('counted_at');
            $table->timestamps();
            $table->unique(['cash_session_id', 'cash_denomination_id', 'count_type'], 'cash_count_details_session_denomination_type_unique');
            $table->index(['cash_session_id', 'count_type'], 'cash_count_details_session_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_count_details');
        Schema::dropIfExists('cash_denominations');
    }
};
