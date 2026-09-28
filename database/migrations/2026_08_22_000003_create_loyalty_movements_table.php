<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('loyalty_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->decimal('points', 19, 4);
            $table->decimal('balance_before', 19, 4);
            $table->decimal('balance_after', 19, 4);
            $table->decimal('base_amount', 19, 4)->nullable();
            $table->decimal('earning_percentage', 19, 4)->nullable();
            $table->decimal('point_value', 19, 4)->nullable();
            $table->string('description', 255);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('related_movement_id')->nullable()->constrained('loyalty_movements')->restrictOnDelete();
            $table->string('event_key', 191)->nullable();
            $table->timestamp('effective_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'event_key'], 'loyalty_movements_company_event_unique');
            $table->index(['loyalty_account_id', 'effective_at'], 'loyalty_movements_account_effective_index');
            $table->index(['company_id', 'branch_id', 'type', 'effective_at'], 'loyalty_movements_context_type_index');
            $table->index(['source_type', 'source_id'], 'loyalty_movements_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_movements');
    }
};
