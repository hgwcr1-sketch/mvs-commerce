<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 13-1A — Aviso administrativo «Migración pendiente de revisar»: marca cada
 * importación directa de un cobrador (clientes o créditos) para que el
 * prestamista la revise después. Aditivo y reversible; no bloquea operaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_migration_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('cobros_route_id')->constrained('cobros_routes')->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->string('source', 20); // customer_import | credit_import
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('details', 255)->nullable();
            $table->string('status', 20)->default('pending'); // pending | reviewed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_migration_notices');
    }
};
