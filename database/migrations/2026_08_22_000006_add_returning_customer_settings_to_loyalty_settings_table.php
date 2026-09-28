<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_settings', function (Blueprint $table) {
            $table->boolean('returning_customer_enabled')->default(false)->after('birthday_points');
            $table->unsignedInteger('returning_customer_days')->default(0)->after('returning_customer_enabled');
            $table->decimal('returning_customer_points', 19, 4)->default(0)->after('returning_customer_days');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_settings', function (Blueprint $table) {
            $table->dropColumn([
                'returning_customer_enabled',
                'returning_customer_days',
                'returning_customer_points',
            ]);
        });
    }
};
