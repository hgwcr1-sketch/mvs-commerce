<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R01 MVS RouteOS — Ubicación del cliente.
 *
 * Los campos viven en `customers` porque la ubicación operativa de visita
 * es un atributo único del cliente (dónde entregar / visitar), no de una
 * dirección adicional del catálogo. `customer_addresses` permanece intacto
 * como libro de direcciones. Migración aditiva y compatible con SQLite/PGSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // latitude: ±90.00000000 → decimal(10,8)
            $table->decimal('latitude', 10, 8)->nullable()->after('address');
            // longitude: ±180.00000000 → decimal(11,8)
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->string('location_reference', 500)->nullable()->after('longitude');
            $table->timestamp('location_validated_at')->nullable()->after('location_reference');
            $table->foreignId('location_validated_by')
                ->nullable()
                ->after('location_validated_at')
                ->constrained('users')
                ->restrictOnDelete();

            $table->index(['company_id', 'latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'latitude', 'longitude']);
            $table->dropConstrainedForeignId('location_validated_by');
            $table->dropColumn([
                'latitude',
                'longitude',
                'location_reference',
                'location_validated_at',
            ]);
        });
    }
};
