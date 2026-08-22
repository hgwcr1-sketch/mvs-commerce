<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->decimal('balance', 19, 4)->default(0);
            $table->decimal('total_earned', 19, 4)->default(0);
            $table->decimal('total_redeemed', 19, 4)->default(0);
            $table->decimal('total_expired', 19, 4)->default(0);
            $table->timestamp('last_qualifying_purchase_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'customer_id'], 'loyalty_accounts_company_customer_unique');
            $table->index(['company_id', 'is_active'], 'loyalty_accounts_company_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_accounts');
    }
};
