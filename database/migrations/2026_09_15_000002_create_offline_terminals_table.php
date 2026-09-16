<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('terminal_uuid', 36)->unique();
            $table->string('name', 120)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->string('secret_hash', 64)->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'branch_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('offline_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offline_terminal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('authorization_id', 36)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('valid_until');
            $table->string('result', 20)->default('granted');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'issued_at']);
            $table->index(['offline_terminal_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_authorizations');
        Schema::dropIfExists('offline_terminals');
    }
};
