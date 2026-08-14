<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_session_mail_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->restrictOnDelete();
            $table->enum('notification_type', ['opened', 'closed']);
            $table->json('recipients');
            $table->json('delivered_recipients')->nullable();
            $table->enum('status', ['pending', 'processing', 'sent', 'failed', 'skipped'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['cash_session_id', 'notification_type'], 'cash_session_mail_notifications_session_type_unique');
            $table->index(['status', 'available_at'], 'cash_session_mail_notifications_status_available_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_session_mail_notifications');
    }
};
