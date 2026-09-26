<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // Identificación
            $table->string('employee_code');
            $table->string('identification');
            $table->string('identification_type')->default('cedula');

            // Información personal
            $table->string('first_name');
            $table->string('last_name');
            $table->date('birth_date')->nullable();

            // Contacto
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();

            // Información laboral
            $table->string('position');
            $table->string('department')->nullable();
            $table->date('hire_date');
            $table->decimal('base_salary', 12, 2);
            $table->string('payment_method')->nullable();
            $table->string('payment_frequency')->default('monthly');
            $table->unsignedTinyInteger('weekly_work_days')->nullable();

            // Estado del empleado
            $table->string('status')->default('active');
            $table->date('termination_date')->nullable();

            // Información bancaria
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('iban')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'employee_code'], 'emp_comp_code_uniq');
            $table->unique(['company_id', 'identification'], 'emp_comp_ident_uniq');
            $table->unique(['id', 'company_id'], 'emp_id_comp_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
