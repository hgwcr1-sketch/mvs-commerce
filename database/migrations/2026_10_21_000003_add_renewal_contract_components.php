<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cobros_credit_renewal_requests', function (Blueprint $table): void {
            $table->string('modality', 32)->default('difference')->index();
            $table->decimal('previous_pending_principal', 19, 4)->default(0);
            $table->decimal('previous_pending_interest', 19, 4)->default(0);
            $table->decimal('previous_pending_late_fee', 19, 4)->default(0);
            $table->json('contract_components')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cobros_credit_renewal_requests', function (Blueprint $table): void {
            $table->dropColumn(['modality', 'previous_pending_principal', 'previous_pending_interest', 'previous_pending_late_fee', 'contract_components']);
        });
    }
};
