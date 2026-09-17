<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 60);
            $table->boolean('enabled')->default(true);
            $table->string('severity_min', 20)->default('INFORMATIVA');
            $table->json('channels')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'role_id', 'type']);
            $table->index(['company_id', 'user_id', 'type']);
        });

        DB::statement('CREATE UNIQUE INDEX notification_preferences_company_type_unique ON notification_preferences (company_id, type) WHERE role_id IS NULL AND user_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX notification_preferences_role_type_unique ON notification_preferences (company_id, role_id, type) WHERE role_id IS NOT NULL AND user_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX notification_preferences_user_type_unique ON notification_preferences (company_id, user_id, type) WHERE user_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
