<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('reason');
            $table->uuid('request_token')->nullable()->after('notes');
            $table->unique(
                ['cash_session_id', 'request_token'],
                'cash_movements_session_request_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropUnique('cash_movements_session_request_unique');
            $table->dropColumn(['notes', 'request_token']);
        });
    }
};
