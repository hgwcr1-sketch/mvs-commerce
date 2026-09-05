<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Tabla loyalty_portal_passkeys ya existe de P37.1-K
        // No creamos tabla nueva; la usaremos tal como está.
        // Esta migración es un no-op para mantener compatibilidad.
    }

    public function down()
    {
        // No hacer nada - la tabla la gestionamos nosotros
    }
};