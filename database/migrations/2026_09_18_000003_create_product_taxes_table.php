<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_profile_id')->constrained()->restrictOnDelete();
            $table->string('role', 20)->default('primary');
            $table->json('additional_tax_data')->nullable();
            $table->string('source', 255)->nullable();
            $table->string('source_version', 100)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'role', 'is_active']);
            $table->unique(['product_id', 'fiscal_profile_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_taxes');
    }
};