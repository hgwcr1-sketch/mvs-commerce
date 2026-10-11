<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_credit_renewal_requests', function (Blueprint $table) {
            $table->foreignId('rejected_by')->nullable()->after('approved_at');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('reject_reason', 255)->nullable()->after('rejected_at');
            $table->timestamp('consented_at')->nullable()->after('reject_reason');
            $table->string('signature_path')->nullable()->after('consented_at');
            $table->string('terms_hash', 64)->nullable()->after('signature_path');
            $table->json('consent_snapshot')->nullable()->after('terms_hash');
        });
    }

    public function down(): void
    {
        Schema::table('cobros_credit_renewal_requests', function (Blueprint $table) {
            $table->dropColumn([
                'rejected_by', 'rejected_at', 'reject_reason',
                'consented_at', 'signature_path', 'terms_hash', 'consent_snapshot',
            ]);
        });
    }
};
