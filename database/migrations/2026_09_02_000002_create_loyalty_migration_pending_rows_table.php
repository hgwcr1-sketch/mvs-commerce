<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_migration_pending_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('loyalty_migration_batches')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('source_key', 100);
            $table->unsignedInteger('row_number');
            $table->json('source_rows');
            $table->json('source_data');
            $table->json('reasons');
            $table->timestamps();
            $table->index(['company_id', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_migration_pending_rows');
    }
};
