<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_portal_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            // Usuario que generó el acceso (auditoría); el acceso del cliente no depende de su existencia.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Solo se almacena el hash SHA-256 del token; el token en claro se muestra una única vez al generarlo.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id'], 'loyalty_portal_accesses_company_customer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_portal_accesses');
    }
};
