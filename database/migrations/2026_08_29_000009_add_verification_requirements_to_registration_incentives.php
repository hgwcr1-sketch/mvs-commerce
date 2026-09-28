<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('mobile');
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
        Schema::table('loyalty_registration_incentives', function (Blueprint $table) {
            $table->boolean('require_verified_phone')->default(false)->after('stacking_allowed');
            $table->boolean('require_verified_email')->default(false)->after('require_verified_phone');
        });
        Schema::table('loyalty_registration_incentive_claims', function (Blueprint $table) {
            $table->boolean('required_verified_phone')->default(false)->after('stacking_allowed');
            $table->boolean('required_verified_email')->default(false)->after('required_verified_phone');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_registration_incentive_claims', fn (Blueprint $table) => $table->dropColumn(['required_verified_phone', 'required_verified_email']));
        Schema::table('loyalty_registration_incentives', fn (Blueprint $table) => $table->dropColumn(['require_verified_phone', 'require_verified_email']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['phone_verified_at', 'email_verified_at']));
    }
};
