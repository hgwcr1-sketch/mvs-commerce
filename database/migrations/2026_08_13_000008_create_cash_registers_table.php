<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'code'], 'cash_registers_company_branch_code_unique');
            $table->index(['company_id', 'branch_id', 'is_active'], 'cash_registers_company_branch_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
