<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 10A - expediente de evaluación: información faltante (trabajo/ingresos,
 * señas, referencias, fiador) ligada a la solicitud. NO toca Customer,
 * términos financieros ni decisión crediticia. Aditiva y segura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_credit_expedientes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('credit_request_id');
            $table->unsignedBigInteger('collector_user_id')->index();
            // Trabajo / ingresos (todo opcional: el expediente puede guardarse incompleto).
            $table->string('occupation', 120)->nullable();
            $table->string('workplace', 150)->nullable();
            $table->decimal('income_amount', 12, 2)->nullable();
            $table->string('income_frequency', 20)->nullable();
            $table->string('activity_time', 60)->nullable();
            // Dirección: la base es Customer/solicitud; aquí solo la referencia.
            $table->string('address_reference', 250)->nullable();
            // Fiador opcional.
            $table->boolean('has_guarantor')->default(false);
            $table->string('guarantor_name', 150)->nullable();
            $table->string('guarantor_identification', 50)->nullable();
            $table->string('guarantor_phone', 30)->nullable();
            $table->string('guarantor_address', 250)->nullable();
            $table->string('guarantor_occupation', 120)->nullable();
            $table->decimal('guarantor_income', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'credit_request_id'], 'ccx_company_request_unique');
        });

        Schema::create('cobros_expediente_references', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('expediente_id')->index();
            $table->string('name', 150);
            $table->string('phone', 30);
            $table->string('relationship', 60);
            $table->timestamps();
        });

        Schema::create('cobros_expediente_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('expediente_id')->index();
            $table->string('doc_type', 20); // income | address | other
            $table->string('path', 255);
            $table->string('original_name', 150)->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();

            $table->unique(['expediente_id', 'doc_type'], 'ced_expediente_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_expediente_documents');
        Schema::dropIfExists('cobros_expediente_references');
        Schema::dropIfExists('cobros_credit_expedientes');
    }
};
