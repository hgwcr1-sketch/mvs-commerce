<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('fingerprint', 64);
            $table->string('original_filename')->nullable();
            $table->string('private_file_path')->nullable();
            $table->string('status', 32)->default('uploaded');
            foreach (['total_rows', 'analyzed_rows', 'new_count', 'existing_count', 'duplicate_count', 'conflict_count', 'error_count', 'created_count', 'ignored_count', 'rejected_count', 'current_row', 'attempts'] as $counter) {
                $table->unsignedBigInteger($counter)->default(0);
            }
            $table->unsignedBigInteger('last_source_row')->nullable();
            $table->text('last_error')->nullable();
            foreach (['confirmed_at', 'started_at', 'finished_at', 'purged_at'] as $date) {
                $table->timestamp($date)->nullable();
            }
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_import_runs');
    }
};
