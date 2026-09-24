<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->foreignId('credit_note_id')
                ->constrained('credit_notes')
                ->restrictOnDelete();

            $table->foreignId('sale_id')
                ->constrained('sales')
                ->restrictOnDelete();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->restrictOnDelete();

            $table->decimal('amount', 19, 4);
            $table->string('application_token', 100);
            $table->foreignId('applied_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('applied_at');
            $table->string('status', 20)->default('applied');
            $table->foreignId('voided_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'application_token'],
                'credit_note_applications_company_token_unique'
            );

            $table->index(
                ['company_id', 'credit_note_id'],
                'credit_note_applications_company_note_index'
            );

            $table->index(
                ['company_id', 'sale_id'],
                'credit_note_applications_company_sale_index'
            );

            $table->index(
                ['company_id', 'customer_id'],
                'credit_note_applications_company_customer_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_applications');
    }
};
