<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->foreignId('payroll_id');

            $table->foreignId('employee_id');

            $table->decimal('base_salary', 15, 2)->default(0);
            $table->decimal('gross_salary', 15, 2)->default(0);
            $table->decimal('employer_subsidy', 15, 2)->default(0);
            $table->decimal('ccss_subsidy', 15, 2)->default(0);
            $table->decimal('disability_subsidy', 15, 2)->default(0);
            $table->decimal('total_earnings', 15, 2)->default(0);
            $table->decimal('total_income', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('net_salary', 15, 2)->default(0);
            $table->decimal('worked_salary', 15, 2)->default(0);
            $table->decimal('vacation_earnings', 15, 2)->default(0);

            $table->decimal('worked_days', 8, 2)->default(0);
            $table->decimal('vacation_days', 8, 2)->default(0);

            $table->string('vacation_data_status', 32)->default('legacy_unavailable');

            $table->timestamps();

            $table->unique(['payroll_id', 'employee_id'], 'pd_pay_emp_uniq');
            $table->unique(['id', 'company_id'], 'pd_id_comp_uniq');

            // Composite FKs for tenant isolation
            $table->foreign(['payroll_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('payrolls')
                ->restrictOnDelete();

            $table->foreign(['employee_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('employees')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_details');
    }
};
