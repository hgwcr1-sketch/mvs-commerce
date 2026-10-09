<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BLOQUE 17A-1 — motor de tarifas y contratos CobrOS.
 *
 * Tablas de PRECIOS y CONTRATOS (sin activación ni facturación todavía):
 *  - cobros_price_plans: tarifa estándar por país+concepto, gestionable por
 *    el Panel Maestro; sembrada con las tarifas vigentes (CR ₡10.000,
 *    CO $60.000).
 *  - cobros_billing_contracts: contrato individual/empresarial con UN único
 *    responsable de pago, vigencia y total mensual (estándar o negociado).
 *  - cobros_contract_seats: cupos del contrato empresarial (posición única).
 *  - cobros_price_overrides: precio personalizado POR CONTRATO (nunca global),
 *    con autorizador, fecha y motivo auditables.
 *
 * Nada aquí crea licencias, rutas ni activa cuentas: eso es 17A-2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_price_plans', function (Blueprint $table) {
            $table->id();
            $table->string('country_iso', 2);
            $table->string('concept', 30); // administration | route | collector
            $table->string('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('reason', 250)->nullable();
            $table->foreignId('authorized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['country_iso', 'concept', 'is_active']);
        });

        Schema::create('cobros_billing_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('billing_mode', 20); // individual | enterprise | mixed
            $table->string('modality', 40)->nullable(); // modalidad CobrOS (13-2A)
            $table->string('country_iso', 2);
            $table->string('currency', 3);
            $table->string('status', 20)->default('draft'); // draft | active | cancelled
            $table->foreignId('payer_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('seats_total')->default(1);
            $table->decimal('monthly_amount', 19, 4);
            $table->boolean('admin_included')->default(false);
            $table->date('valid_from');
            $table->date('valid_until');
            $table->foreignId('authorized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorized_at')->nullable();
            $table->string('authorization_reason', 250)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('payer_user_id');
        });

        Schema::create('cobros_contract_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_contract_id')->constrained('cobros_billing_contracts')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('status', 20)->default('available'); // available | assigned
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['billing_contract_id', 'position']);
        });

        Schema::create('cobros_price_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_contract_id')->constrained('cobros_billing_contracts')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('concept', 30); // administration | route | collector | monthly_total
            $table->string('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->date('valid_from');
            $table->date('valid_until');
            $table->boolean('is_active')->default(true);
            $table->foreignId('authorized_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('authorized_at');
            $table->string('reason', 250);
            $table->timestamps();

            $table->index(['billing_contract_id', 'concept', 'is_active']);
        });

        $this->seedStandardPrices();
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_price_overrides');
        Schema::dropIfExists('cobros_contract_seats');
        Schema::dropIfExists('cobros_billing_contracts');
        Schema::dropIfExists('cobros_price_plans');
    }

    /** Tarifas estándar vigentes: CR ₡10.000; CO $60.000 (unidad base). */
    private function seedStandardPrices(): void
    {
        $rows = [
            ['CR', 'CRC', 'administration', '10000.0000', 'Tarifa estándar CR: administración del prestamista.'],
            ['CR', 'CRC', 'route', '10000.0000', 'Tarifa estándar CR: ruta individual del cobrador.'],
            ['CR', 'CRC', 'collector', '10000.0000', 'Tarifa estándar CR: licencia de cobrador.'],
            ['CO', 'COP', 'administration', '60000.0000', 'Tarifa estándar CO: administración del prestamista.'],
            ['CO', 'COP', 'route', '60000.0000', 'Tarifa estándar CO: ruta individual.'],
            ['CO', 'COP', 'collector', '60000.0000', 'Tarifa estándar CO: licencia de cobrador.'],
        ];

        $now = now();

        foreach ($rows as [$iso, $currency, $concept, $amount, $reason]) {
            DB::table('cobros_price_plans')->insert([
                'country_iso' => $iso,
                'concept' => $concept,
                'currency' => $currency,
                'amount' => $amount,
                'is_active' => true,
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
