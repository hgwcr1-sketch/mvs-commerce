<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cobros_assignment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_collector_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('to_collector_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_assignment_logs');
    }
};
