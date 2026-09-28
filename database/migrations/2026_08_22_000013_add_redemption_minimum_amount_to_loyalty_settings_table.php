<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_settings', function (Blueprint $table) {
            $table->boolean('redemption_minimum_enabled')->default(false)->after('minimum_redemption_points');
            $table->decimal('redemption_minimum_amount', 19, 4)->default(0)->after('redemption_minimum_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_settings', function (Blueprint $table) {
            $table->dropColumn(['redemption_minimum_enabled', 'redemption_minimum_amount']);
        });
    }
};
