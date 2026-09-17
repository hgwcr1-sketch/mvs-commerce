<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 60);
            $table->string('severity', 20)->default('INFORMATIVA');
            $table->string('status', 20)->default('NUEVA');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('entity_type', 120)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('dedupe_hash', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'occurred_at']);
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'type', 'status']);
            $table->index(['dedupe_hash', 'company_id']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
