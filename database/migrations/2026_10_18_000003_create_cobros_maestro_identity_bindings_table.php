<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_maestro_identity_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('maestro_application_id', 190);
            $table->string('maestro_installation_id', 190);
            $table->string('source_system', 40)->default('cobros');
            $table->string('external_user_id', 190);
            $table->string('accreditation_id', 190);
            $table->string('status', 20)->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'company_id', 'maestro_application_id', 'maestro_installation_id'], 'cobros_maestro_binding_scope_unique');
            $table->unique(['maestro_installation_id', 'source_system', 'external_user_id'], 'cobros_maestro_binding_external_unique');
            $table->index(['company_id', 'status', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_maestro_identity_bindings');
    }
};
