<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_pending_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_uuid')->unique();
            $table->string('operation_type', 50);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('terminal_uuid', 36);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at_local');
            $table->json('payload');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'terminal_uuid', 'status']);
            $table->index(['company_id', 'status', 'created_at_local']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_pending_operations');
    }
};
