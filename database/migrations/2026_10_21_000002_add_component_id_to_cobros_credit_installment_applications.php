<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\IndexDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_credit_installment_applications', function (Blueprint $table): void {
            $table->foreignId('component_id')->nullable()->after('installment_id')->constrained('cobros_credit_installment_components')->nullOnDelete();
        });
        Schema::table('cobros_credit_installment_applications', function (Blueprint $table): void {
            $table->dropUnique(['cobros_receipt_id', 'installment_id']);
        });
        $driver = DB::connection()->getDriverName();
        $table = 'cobros_credit_installment_applications';
        $legacy = 'cobros_credit_installment_applications_receipt_installment_legacy_unique';
        $split = 'cobros_credit_installment_applications_receipt_installment_component_unique';
        DB::statement("CREATE UNIQUE INDEX {$legacy} ON {$table} (cobros_receipt_id, installment_id) WHERE component_id IS NULL");
        DB::statement("CREATE UNIQUE INDEX {$split} ON {$table} (cobros_receipt_id, installment_id, component_id) WHERE component_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cobros_credit_installment_applications_receipt_installment_legacy_unique');
        DB::statement('DROP INDEX IF EXISTS cobros_credit_installment_applications_receipt_installment_component_unique');
        Schema::table('cobros_credit_installment_applications', function (Blueprint $table): void {
            $table->unique(['cobros_receipt_id', 'installment_id']);
            $table->dropForeign(['component_id']);
            $table->dropColumn('component_id');
        });
    }
};
