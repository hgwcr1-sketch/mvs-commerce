<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cobros_registration_drafts', function (Blueprint $table) {
            $table->foreignId('billing_contract_id')
                ->nullable()
                ->constrained('cobros_billing_contracts')
                ->nullOnDelete()
                ->after('email');
            $table->index('billing_contract_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cobros_registration_drafts', function (Blueprint $table) {
            $table->dropForeign(['billing_contract_id']);
            $table->dropIndex(['billing_contract_id']);
            $table->dropColumn('billing_contract_id');
        });
    }
};