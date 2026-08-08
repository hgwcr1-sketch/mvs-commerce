<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {

            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('from_branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->foreignId('to_branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('transfer_number')->unique();

            $table->string('status', 30)
                ->default('completed');

            $table->text('notes')->nullable();

            $table->timestamp('transferred_at')->nullable();

            $table->timestamps();

            $table->index([
                'company_id',
                'from_branch_id',
                'to_branch_id'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};