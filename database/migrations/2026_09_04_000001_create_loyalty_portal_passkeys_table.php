<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_portal_passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('credential_id_ref')->constrained('loyalty_portal_credentials')->cascadeOnDelete();
            $table->string('credential_id', 64);
            $table->string('name', 80);
            $table->string('algorithm', 20)->default('ES256');
            $table->text('public_key_jwk');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('registered_ip', 45)->nullable();
            $table->string('registered_user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'credential_id']);
            $table->index(['company_id', 'customer_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_portal_passkeys');
    }
};
