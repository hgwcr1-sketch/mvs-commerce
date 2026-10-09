<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BLOQUE 13-2A — modalidades CobrOS por membresía (aditivo, nullable).
     * La modalidad vive en company_user para NO tocar el role_id de los
     * miembros existentes ni el catálogo global de roles/permisos.
     */
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->string('cobros_modality', 50)
                ->nullable()
                ->after('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->dropColumn('cobros_modality');
        });
    }
};
