<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_registration_incentives', function (Blueprint $table) {
            $table->string('benefit_type', 20)->default('points')->after('is_enabled');
            $table->decimal('benefit_value', 19, 4)->default(10)->after('benefit_type');
        });

        Schema::table('loyalty_registration_incentive_claims', function (Blueprint $table) {
            $table->decimal('benefit_value', 19, 4)->nullable()->change();
            $table->decimal('awarded_points', 19, 4)->nullable()->change();
            $table->decimal('discount_amount', 19, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_registration_incentive_claims', function (Blueprint $table) {
            $table->decimal('benefit_value', 12, 4)->nullable()->change();
            $table->decimal('awarded_points', 12, 4)->nullable()->change();
            $table->decimal('discount_amount', 12, 2)->nullable()->change();
        });

        Schema::table('loyalty_registration_incentives', function (Blueprint $table) {
            $table->dropColumn(['benefit_type', 'benefit_value']);
        });
    }
};
