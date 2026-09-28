<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_registration_incentives', function (Blueprint $table) {
            $table->boolean('minimum_purchase_enabled')->default(false)->after('benefit_value');
            $table->decimal('minimum_purchase_amount', 19, 4)->default(0)->after('minimum_purchase_enabled');
            $table->string('award_timing', 30)->default('registration')->after('minimum_purchase_amount');
            $table->boolean('allow_on_first_purchase')->default(true)->after('award_timing');
            $table->boolean('bypass_redemption_minimum')->default(false)->after('allow_on_first_purchase');
            $table->boolean('expiration_enabled')->default(false)->after('bypass_redemption_minimum');
            $table->unsignedInteger('expiration_days')->nullable()->after('expiration_enabled');
        });

        Schema::table('loyalty_registration_incentive_claims', function (Blueprint $table) {
            $table->string('award_timing', 30)->default('registration')->after('benefit_value');
            $table->decimal('minimum_purchase_amount', 19, 4)->default(0)->after('award_timing');
            $table->boolean('allow_on_first_purchase')->default(true)->after('minimum_purchase_amount');
            $table->boolean('bypass_redemption_minimum')->default(false)->after('allow_on_first_purchase');
            $table->foreignId('qualification_sale_id')->nullable()->after('sale_id')->constrained('sales')->nullOnDelete();
            $table->timestamp('available_at')->nullable()->after('qualification_sale_id');
            $table->timestamp('expires_at')->nullable()->after('available_at');
            $table->timestamp('expired_at')->nullable()->after('expires_at');
            $table->timestamp('used_at')->nullable()->after('expired_at');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_registration_incentive_claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('qualification_sale_id');
            $table->dropColumn([
                'award_timing',
                'minimum_purchase_amount',
                'allow_on_first_purchase',
                'bypass_redemption_minimum',
                'available_at',
                'expires_at',
                'expired_at',
                'used_at',
            ]);
        });

        Schema::table('loyalty_registration_incentives', function (Blueprint $table) {
            $table->dropColumn([
                'minimum_purchase_enabled',
                'minimum_purchase_amount',
                'award_timing',
                'allow_on_first_purchase',
                'bypass_redemption_minimum',
                'expiration_enabled',
                'expiration_days',
            ]);
        });
    }
};
