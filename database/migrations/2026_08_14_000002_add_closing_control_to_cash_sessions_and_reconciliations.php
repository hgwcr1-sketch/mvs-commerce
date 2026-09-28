<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->timestamp('closing_started_at')->nullable()->after('opened_at');
            $table->foreignId('closing_started_by')->nullable()->after('closing_started_at')->constrained('users')->restrictOnDelete();
            $table->uuid('closing_request_token')->nullable()->after('closing_started_by')->unique('cash_sessions_closing_request_unique');
            $table->uuid('closing_confirmation_token')->nullable()->after('closing_request_token')->unique('cash_sessions_closing_confirmation_unique');
            $table->timestamp('closing_submitted_at')->nullable()->after('closing_confirmation_token');
        });

        Schema::table('cash_payment_reconciliations', function (Blueprint $table) {
            $table->string('payment_method_code_snapshot', 50)->nullable()->after('payment_method_id');
            $table->string('payment_method_name_snapshot', 100)->nullable()->after('payment_method_code_snapshot');
            $table->string('payment_method_type_snapshot', 30)->nullable()->after('payment_method_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('cash_payment_reconciliations', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method_code_snapshot',
                'payment_method_name_snapshot',
                'payment_method_type_snapshot',
            ]);
        });

        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropUnique('cash_sessions_closing_request_unique');
            $table->dropUnique('cash_sessions_closing_confirmation_unique');
            $table->dropForeign(['closing_started_by']);
            $table->dropColumn([
                'closing_started_at',
                'closing_started_by',
                'closing_request_token',
                'closing_confirmation_token',
                'closing_submitted_at',
            ]);
        });
    }
};
