<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_migration_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('source_key', 100);
            $table->unsignedInteger('row_count');
            $table->timestamp('imported_at');
            $table->timestamps();
            $table->unique(['company_id', 'source_key'], 'inventory_migration_company_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_migration_batches');
    }
};
