<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cobros_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->unique(['company_id', 'customer_id']);
            $table->index(['company_id', 'collector_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_assignments');
    }
};
