<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('cash_register_id')->constrained('cash_registers')->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('direction', 10);
            $table->decimal('amount', 19, 4);
            $table->string('concept', 150);
            $table->text('reason');
            $table->nullableMorphs('source');
            $table->foreignId('reversed_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['cash_session_id', 'occurred_at'], 'cash_movements_session_occurred_index');
            $table->index(['company_id', 'branch_id', 'type'], 'cash_movements_company_branch_type_index');
        });

        Schema::create('cash_session_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->restrictOnDelete();
            $table->string('event_type', 50);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['cash_session_id', 'occurred_at'], 'cash_session_events_session_occurred_index');
            $table->index(['event_type', 'occurred_at'], 'cash_session_events_type_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_session_events');
        Schema::dropIfExists('cash_movements');
    }
};
