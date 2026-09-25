<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_backup_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('plan', 30)->default('basico');
            $table->string('frequency', 20)->default('diario');
            $table->unsignedInteger('retention_days')->default(30);
            $table->boolean('manual_backup_allowed')->default(true);
            $table->string('external_copy', 20)->default('preparada');
            $table->boolean('encryption_required')->default(false);
            $table->timestamp('next_backup_at')->nullable();
            $table->timestamp('last_backup_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->unsignedBigInteger('last_size_bytes')->nullable();
            $table->text('last_message')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_enabled', 'next_backup_at']);
        });

        Schema::create('company_backup_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20)->default('manual');
            $table->string('status', 20)->default('running');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('message')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_backup_records');
        Schema::dropIfExists('company_backup_settings');
    }
};
