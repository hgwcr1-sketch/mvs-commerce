<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_catalog_version_id')->constrained()->restrictOnDelete();
            $table->string('tax_code', 2);
            $table->string('tax_rate_code', 2)->nullable();
            $table->string('name', 160);
            $table->string('treatment', 40);
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('factor_iva', 8, 6)->nullable();
            $table->string('tax_rate_other', 160)->nullable();
            $table->json('document_types')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['fiscal_catalog_version_id', 'tax_code', 'tax_rate_code']);
            $table->index(['tax_code', 'tax_rate_code', 'is_active']);
        });

        $versionId = DB::table('fiscal_catalog_versions')->insertGetId([
            'source' => 'Hacienda v4.4 / Facturaencr OpenAPI',
            'source_version' => 'v4.4',
            'status' => 'active',
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $profiles = [
            ['01', '01', 'IVA 0%', 'zero_rate', 0, null, ['01', '02', '03', '04']],
            ['01', '02', 'IVA 1%', 'reduced_rate', 1, null, ['01', '02', '03', '04']],
            ['01', '03', 'IVA 2%', 'reduced_rate', 2, null, ['01', '02', '03', '04']],
            ['01', '04', 'IVA 4%', 'reduced_rate', 4, null, ['01', '02', '03', '04']],
            ['01', '05', 'IVA transitorio 0%', 'transitional', 0, null, ['02', '03']],
            ['01', '06', 'IVA transitorio 4%', 'transitional', 4, null, ['02', '03']],
            ['01', '07', 'IVA transitorio 8%', 'transitional', 8, null, ['02', '03']],
            ['01', '08', 'IVA 13%', 'taxable', 13, null, ['01', '02', '03', '04']],
            ['01', '09', 'IVA 0.5%', 'reduced_rate', 0.5, null, ['01', '02', '03', '04']],
            ['01', '10', 'IVA exento', 'exempt', 0, null, ['01', '02', '03', '04', '09']],
            ['01', '11', 'IVA no sujeto', 'not_subject', 0, null, ['01', '02', '03', '04']],
            ['02', null, 'Selectivo de Consumo', 'additional_tax', null, null, ['01', '02', '03', '04']],
            ['03', null, 'Impuesto Único a los Combustibles', 'additional_tax', null, null, ['01', '02', '03', '04']],
            ['04', null, 'Bebidas alcohólicas', 'additional_tax', null, null, ['01', '02', '03', '04']],
            ['05', null, 'Bebidas sin alcohol y jabones', 'additional_tax', null, null, ['01', '02', '03', '04']],
            ['06', null, 'Tabaco', 'additional_tax', null, null, ['01', '02', '03', '04']],
            ['07', null, 'IVA de cálculo especial', 'taxable', null, null, ['01', '02', '03', '04']],
            ['08', null, 'IVA bienes usados', 'taxable', null, null, ['01', '02', '03', '04']],
            ['12', null, 'Cemento', 'additional_tax', 5, null, ['01', '02', '03', '04']],
            ['99', null, 'Otros impuestos', 'additional_tax', null, null, ['01', '02', '03', '04']],
        ];

        foreach ($profiles as [$taxCode, $rateCode, $name, $treatment, $rate, $factor, $documentTypes]) {
            DB::table('fiscal_profiles')->insert([
                'fiscal_catalog_version_id' => $versionId,
                'tax_code' => $taxCode,
                'tax_rate_code' => $rateCode,
                'name' => $name,
                'treatment' => $treatment,
                'rate' => $rate,
                'factor_iva' => $factor,
                'document_types' => json_encode($documentTypes),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_profiles');
    }
};