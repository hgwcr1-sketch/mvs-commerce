<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('is_historical')->default(false)->after('status');
            $table->index(['company_id', 'is_historical', 'completed_at'], 'sales_company_historical_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_company_historical_date_index');
            $table->dropColumn('is_historical');
        });
    }
};
