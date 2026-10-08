<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 12B — la importación de créditos del PRESTAMISTA (owner) queda desacoplada
 * del ciclo 5A/5B: esos registros no pertenecen a ninguna migración manual
 * del cobrador, por lo que migration_id pasa a ser nullable.
 *
 * - La carga manual 5B (create/assertOpen) sigue exigiendo migración abierta
 *   y sigue ligando sus filas a migration_id como hasta ahora.
 * - down() vuelve a NOT NULL: SI existen filas importadas (NULL) la reversión
 *   FALLA de forma segura (constraint + DDL transaccional) SIN borrar créditos,
 *   CxC ni registros importados; revierte limpio solo en BD sin importaciones.
 *
 * Aditivo y reversible; no toca filas existentes ni su FK.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('cobros_initial_portfolio_records', function (Blueprint $table) {
            $table->foreignId('migration_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cobros_initial_portfolio_records', function (Blueprint $table) {
            $table->foreignId('migration_id')->nullable(false)->change();
        });
    }
};
