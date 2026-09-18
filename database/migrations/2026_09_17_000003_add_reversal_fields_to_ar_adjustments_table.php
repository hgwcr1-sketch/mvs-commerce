<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('accounts_receivable_adjustments', function (Blueprint $table) {
            $table->decimal('reversed_amount', 19, 4)->default(0)->after('amount');
            $table->foreignId('reversal_adjustment_id')
                ->nullable()
                ->constrained('accounts_receivable_adjustments')
                ->nullOnDelete()
                ->after('reversed_amount');

            $table->index(
                ['company_id', 'reversal_adjustment_id'],
                'ar_adjustments_company_reversal_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivable_adjustments', function (Blueprint $table) {
            $table->dropIndex('ar_adjustments_company_reversal_index');
            $table->dropForeign(['reversal_adjustment_id']);
            $table->dropColumn(['reversed_amount', 'reversal_adjustment_id']);
        });
    }
};
