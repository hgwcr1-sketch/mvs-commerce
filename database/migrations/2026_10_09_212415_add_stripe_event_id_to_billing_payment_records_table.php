<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOQUE 17B-2B — deduplicación durable por event.id de Stripe.
 *
 * Añade columna stripe_event_id con índice único para evitar
 * procesar dos veces el mismo evento de Stripe (distinto de
 * payment_intent.id, que puede repetirse en reembolsos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payment_records', function (Blueprint $table) {
            $table->string('stripe_event_id', 190)->nullable()->unique()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_payment_records', function (Blueprint $table) {
            $table->dropUnique(['stripe_event_id']);
            $table->dropColumn('stripe_event_id');
        });
    }
};