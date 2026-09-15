<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mvs_print_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->uuid('terminal_uuid')->index();
            $table->string('name');
            $table->string('printer_name')->nullable();
            $table->enum('paper_width', ['58', '80'])->default('80');
            $table->boolean('auto_print')->default(false);
            $table->boolean('auto_cut')->default(true);
            $table->boolean('open_drawer')->default(false);
            $table->json('drawer_command')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'terminal_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mvs_print_terminals');
    }
};