<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 5B — clientes/crédito cargados uno por uno dentro de la migración 5A.
 * Append-only: cada fila queda ligada a su migración con source
 * (cartera_inicial), verificación (informacion_declarada) y quién/cuándo.
 * No toca clientes/CxC existentes ni crea eventos de pagos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_initial_portfolio_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_id')->constrained('cobros_initial_portfolio_migrations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('identification_type', 20); // cedula | dimex | pasaporte
            $table->string('source', 30)->default('cartera_inicial');
            $table->string('verification', 40)->default('informacion_declarada');
            $table->string('alt_contact_name', 120)->nullable();
            $table->string('alt_contact_phone', 30)->nullable();
            $table->decimal('original_amount', 19, 4)->nullable();
            $table->decimal('balance', 19, 4);
            $table->decimal('installment', 19, 4);
            $table->string('frequency', 20);
            $table->date('next_charge_date');
            $table->unsignedTinyInteger('prior_credits')->nullable();
            $table->string('declared_behavior', 20);
            $table->string('historical_note', 500)->nullable();
            $table->boolean('customer_reused')->default(false);
            $table->foreignId('migrated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('migrated_at');
            $table->timestamps();

            $table->index(['company_id', 'migration_id'], 'cip_records_company_migration_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_initial_portfolio_records');
    }
};
