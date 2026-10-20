<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_referral_attributions', function (Blueprint $table): void {
            // External Maestro identifiers. They are populated only by a future
            // authenticated Maestro linking response; no local-ID inference.
            $table->string('maestro_referral_attribution_id', 190)->nullable()->after('captured_at');
            $table->string('maestro_referred_client_id', 190)->nullable()->after('maestro_referral_attribution_id');
            $table->string('maestro_installation_id', 190)->nullable()->after('maestro_referred_client_id');
            $table->unique('maestro_referral_attribution_id', 'cobros_referral_maestro_attr_unique');
            $table->index(['company_id', 'maestro_installation_id'], 'cobros_referral_maestro_installation_index');
        });
    }

    public function down(): void
    {
        Schema::table('cobros_referral_attributions', function (Blueprint $table): void {
            $table->dropUnique('cobros_referral_maestro_attr_unique');
            $table->dropIndex('cobros_referral_maestro_installation_index');
            $table->dropColumn(['maestro_referral_attribution_id', 'maestro_referred_client_id', 'maestro_installation_id']);
        });
    }
};
