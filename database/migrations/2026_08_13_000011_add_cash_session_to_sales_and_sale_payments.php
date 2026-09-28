<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('user_id')->constrained('cash_sessions')->restrictOnDelete();
            $table->index(['cash_session_id', 'status'], 'sales_cash_session_status_index');
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('sale_id')->constrained('cash_sessions')->restrictOnDelete();
            $table->boolean('affects_cash_snapshot')->nullable()->after('payment_method_id');
            $table->decimal('cash_effect_amount', 19, 4)->nullable()->after('change_amount');
            $table->index(['cash_session_id', 'status'], 'sale_payments_cash_session_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropIndex('sale_payments_cash_session_status_index');
            $table->dropForeign(['cash_session_id']);
            $table->dropColumn(['cash_session_id', 'affects_cash_snapshot', 'cash_effect_amount']);
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_cash_session_status_index');
            $table->dropForeign(['cash_session_id']);
            $table->dropColumn('cash_session_id');
        });
    }
};
