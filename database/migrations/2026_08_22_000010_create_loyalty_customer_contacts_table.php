<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('opportunity_type', 30);
            $table->string('channel', 20)->default('whatsapp');
            $table->timestamp('contacted_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'customer_id', 'opportunity_type', 'contacted_at'], 'loyalty_contacts_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_customer_contacts');
    }
};
