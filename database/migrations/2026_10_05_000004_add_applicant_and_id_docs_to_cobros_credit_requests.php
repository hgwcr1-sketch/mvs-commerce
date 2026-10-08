<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cobros_credit_requests', function (Blueprint $table) {
            // Tipo de solicitante: nuevo (captura manual) o existente (vinculado).
            $table->string('applicant_type', 20)->default('nuevo')->after('customer_id');
            // Evidencias de identidad en disco PRIVADO (disk 'local', sin URL pública).
            $table->string('id_front_path', 255)->nullable()->after('notes');
            $table->string('id_back_path', 255)->nullable()->after('id_front_path');
        });
    }

    public function down(): void
    {
        Schema::table('cobros_credit_requests', function (Blueprint $table) {
            $table->dropColumn(['applicant_type', 'id_front_path', 'id_back_path']);
        });
    }
};
