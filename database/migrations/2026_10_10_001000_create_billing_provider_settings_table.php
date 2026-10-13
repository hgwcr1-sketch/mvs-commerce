<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOQUE 18E-1A — configuración persistente de proveedores por producto y país.
 *
 * Tabla neutra (prefijo billing_) administrada exclusivamente por Platform
 * Admin (sin interfaz todavía). Guarda SOLO la elección comercial:
 * producto, país, proveedor, modo (test/live) y si está habilitado.
 * NO almacena credenciales: las claves secretas siguen únicamente en
 * variables de entorno (STRIPE_*_TEST / STRIPE_*_LIVE).
 *
 * Unicidad por (product, country_iso): una sola elección explícita por
 * producto+país; si está deshabilitada o el proveedor no está disponible,
 * el registro NO cae a otro proveedor (indisponible seguro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->string('product', 60);
            $table->string('country_iso', 2);
            $table->string('provider', 40);
            $table->string('mode', 10)->default('test');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product', 'country_iso'], 'billing_provider_setting_unique_product_country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_provider_settings');
    }
};
