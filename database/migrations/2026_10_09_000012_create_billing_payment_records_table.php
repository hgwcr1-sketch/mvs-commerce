<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOQUE 17B-1 — registro genérico de pagos multi-proveedor MVS.
 *
 * Tabla neutra (prefijo billing_, sin FK a modelos CobrOS) que soporta
 * idempotencia por proveedor + identificador externo y aislamiento por
 * producto/empresa. Otros productos pueden reusarla sin depender de CobrOS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payment_records', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('external_id', 190);
            $table->string('product', 60);
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('contract_reference', 190)->nullable()->index();
            $table->string('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->unsignedSmallInteger('period_year')->nullable();
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->string('status', 30)->default('pending');
            $table->json('raw_payload')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id'], 'billing_payment_unique_provider_external');
            $table->index(['product', 'company_id', 'status'], 'billing_payment_product_company_status');
            $table->index(['product', 'contract_reference', 'period_year', 'period_month'], 'billing_payment_contract_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_records');
    }
};
