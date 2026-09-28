<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_registration_incentives', function (Blueprint $table) {
            $table->json('participating_branch_ids')->nullable()->after('expiration_days');
            $table->boolean('allow_offer_products')->default(true)->after('participating_branch_ids');
            $table->boolean('maximum_discount_enabled')->default(false)->after('allow_offer_products');
            $table->decimal('maximum_discount_amount', 19, 4)->default(0)->after('maximum_discount_enabled');
            $table->boolean('stacking_allowed')->default(true)->after('maximum_discount_amount');
        });

        Schema::table('loyalty_registration_incentive_claims', function (Blueprint $table) {
            $table->json('participating_branch_ids')->nullable()->after('bypass_redemption_minimum');
            $table->boolean('allow_offer_products')->default(true)->after('participating_branch_ids');
            $table->decimal('maximum_discount_amount', 19, 4)->nullable()->after('allow_offer_products');
            $table->boolean('stacking_allowed')->default(true)->after('maximum_discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_registration_incentive_claims', function (Blueprint $table) {
            $table->dropColumn(['participating_branch_ids', 'allow_offer_products', 'maximum_discount_amount', 'stacking_allowed']);
        });

        Schema::table('loyalty_registration_incentives', function (Blueprint $table) {
            $table->dropColumn(['participating_branch_ids', 'allow_offer_products', 'maximum_discount_enabled', 'maximum_discount_amount', 'stacking_allowed']);
        });
    }
};
