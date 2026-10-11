<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_credit_renewal_requests', function (Blueprint $table) {
            $table->foreignId('activated_credit_id')->nullable()->after('consent_snapshot')
                ->constrained('cobros_credits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cobros_credit_renewal_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activated_credit_id');
        });
    }
};
