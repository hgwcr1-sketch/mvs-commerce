<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasExistingCustomers = DB::table('customers')->exists();

        if (
            $hasExistingCustomers
            && ! DB::table('companies')->where('id', 1)->exists()
        ) {
            throw new \RuntimeException(
                'No existe la empresa 1 requerida para asignar los clientes existentes.'
            );
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->restrictOnDelete();
        });

        if ($hasExistingCustomers) {
            DB::table('customers')->update([
                'company_id' => 1,
            ]);
        }

        if (DB::table('customers')->whereNull('company_id')->exists()) {
            throw new \RuntimeException(
                'No fue posible asignar una empresa a todos los clientes existentes.'
            );
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable(false)
                ->change();

            $table->dropUnique('customers_identification_unique');

            $table->unique(
                ['company_id', 'identification'],
                'customers_company_identification_unique'
            );

            $table->index(
                ['company_id', 'is_active'],
                'customers_company_active_index'
            );

            $table->index(
                ['company_id', 'customer_type'],
                'customers_company_type_index'
            );

            $table->index(
                ['company_id', 'name'],
                'customers_company_name_index'
            );
        });
    }

    public function down(): void
    {
        $duplicateIdentificationExists = DB::table('customers')
            ->select('identification')
            ->whereNotNull('identification')
            ->groupBy('identification')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateIdentificationExists) {
            throw new \RuntimeException(
                'No se puede restaurar la unicidad global: existen identificaciones repetidas entre empresas.'
            );
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_company_active_index');
            $table->dropIndex('customers_company_type_index');
            $table->dropIndex('customers_company_name_index');
            $table->dropUnique('customers_company_identification_unique');
            $table->unique('identification');
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
