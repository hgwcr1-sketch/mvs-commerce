<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('customer_import_runs')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('source_row');
            $table->json('data')->nullable();
            $table->string('kind', 24);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('duplicate_of_row')->nullable();
            $table->foreignId('matched_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('created_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            foreach (['identification_key', 'phone_key', 'mobile_key', 'email_key'] as $key) {
                $table->string($key, 64)->nullable();
                $table->index(['run_id', $key]);
            }
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'source_row']);
            $table->index(['run_id', 'kind']);
            $table->index(['company_id', 'run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_import_rows');
    }
};
