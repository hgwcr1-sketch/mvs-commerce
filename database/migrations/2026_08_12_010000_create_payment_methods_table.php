<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->string('code', 50);
            $table->string('name', 100);
            $table->string('type', 30);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('affects_cash')->default(false);
            $table->boolean('requires_reference')->default(false);
            $table->boolean('allows_change')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['company_id', 'code'],
                'payment_methods_company_code_unique'
            );

            $table->index(
                ['company_id', 'is_active', 'sort_order'],
                'payment_methods_company_active_sort_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
