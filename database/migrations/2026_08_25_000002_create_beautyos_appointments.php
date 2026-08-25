<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['id', 'company_id'], 'customers_id_company_unique');
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('reserved');
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('no_show_at')->nullable();
            $table->boolean('deposit_required')->default(false);
            $table->decimal('deposit_amount', 19, 4)->nullable();
            $table->string('deposit_status', 20)->nullable();
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'appointments_id_company_unique');
            $table->index(['company_id', 'branch_id', 'starts_at'], 'appointments_company_branch_starts_index');
            $table->index(['company_id', 'professional_id', 'starts_at'], 'appointments_company_professional_starts_index');
            $table->index(['company_id', 'customer_id', 'starts_at'], 'appointments_company_customer_starts_index');
            $table->index(['company_id', 'status'], 'appointments_company_status_index');
            $table->index(['company_id', 'starts_at', 'ends_at'], 'appointments_company_starts_ends_index');
            $table->foreign(['branch_id', 'company_id'], 'appointments_branch_company_foreign')
                ->references(['id', 'company_id'])
                ->on('branches')
                ->cascadeOnDelete();
            $table->foreign(['customer_id', 'company_id'], 'appointments_customer_company_foreign')
                ->references(['id', 'company_id'])
                ->on('customers')
                ->cascadeOnDelete();
            $table->foreign(['professional_id', 'company_id'], 'appointments_professional_company_foreign')
                ->references(['id', 'company_id'])
                ->on('professionals')
                ->cascadeOnDelete();
            $table->foreign(['service_id', 'company_id'], 'appointments_service_company_foreign')
                ->references(['id', 'company_id'])
                ->on('services')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_id_company_unique');
        });
    }
};
