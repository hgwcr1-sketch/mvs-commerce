<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_portal_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('show_active_offers')->default(true);
            $table->string('welcome_message', 300)->nullable();
            $table->timestamps();
        });

        foreach ([
            ['fidelidad.portal.ver', 'Ver y previsualizar Portal de Clientes'],
            ['fidelidad.portal.configurar', 'Configurar Portal de Clientes'],
            ['fidelidad.portal.contenido', 'Gestionar contenido del Portal de Clientes'],
            ['fidelidad.portal.enlaces', 'Gestionar enlaces del Portal de Clientes'],
        ] as [$name, $label]) {
            DB::table('permissions')->updateOrInsert(['name' => $name], ['label' => $label, 'module' => 'Fidelidad', 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_portal_settings');
    }
};
