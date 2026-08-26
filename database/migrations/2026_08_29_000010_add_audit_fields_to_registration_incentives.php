<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_registration_incentives', function (Blueprint $table) {
            $table->foreignId('configured_by')->nullable()->after('company_id')->constrained('users')->nullOnDelete();
        });
        Schema::table('loyalty_registration_incentive_claims', function (Blueprint $table) {
            $table->foreignId('incentive_rule_id')->nullable()->after('customer_id')->constrained('loyalty_registration_incentives')->nullOnDelete();
            $table->timestamp('awarded_at')->nullable()->after('configured_by');
            $table->index(['company_id', 'awarded_at'], 'registration_incentive_claims_audit_index');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_registration_incentive_claims', function (Blueprint $table) {
            $table->dropIndex('registration_incentive_claims_audit_index');
            $table->dropConstrainedForeignId('incentive_rule_id');
            $table->dropColumn('awarded_at');
        });
        Schema::table('loyalty_registration_incentives', fn (Blueprint $table) => $table->dropConstrainedForeignId('configured_by'));
    }
};
