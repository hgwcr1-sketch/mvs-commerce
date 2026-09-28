<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->string('payroll_number')->unique();

            $table->date('period_start');
            $table->date('period_end');

            $table->string('frequency');
            $table->string('status')->default('borrador');

            $table->timestamps();

            $table->unique(['id', 'company_id'], 'pay_id_comp_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
