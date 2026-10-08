<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias de accesos rápidos CobrOS por USUARIO (no por empresa).
 * Aditiva y segura: crea tabla nueva, sin tocar tablas existentes.
 * Compatible SQLite y PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_quick_nav_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('shortcuts')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_quick_nav_preferences');
    }
};
