<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reward_id')->constrained('loyalty_rewards')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('loyalty_movement_id')->nullable()->constrained('loyalty_movements')->restrictOnDelete();
            $table->string('event_key', 191);
            $table->string('reward_name', 120);
            $table->string('reward_type', 20);
            $table->string('availability_mode', 20);
            $table->string('product_name', 255)->nullable();
            $table->decimal('points_cost', 19, 4);
            $table->timestamps();

            $table->unique(['company_id', 'event_key'], 'loyalty_reward_redemptions_company_event_unique');
            $table->index(['company_id', 'created_at'], 'loyalty_reward_redemptions_company_created_index');
            $table->index(['company_id', 'customer_id'], 'loyalty_reward_redemptions_company_customer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_reward_redemptions');
    }
};
