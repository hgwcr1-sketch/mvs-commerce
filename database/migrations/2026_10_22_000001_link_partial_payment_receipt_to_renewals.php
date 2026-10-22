<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cobros_credit_renewal_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('partial_payment_receipt_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('cobros_credit_renewal_requests', function (Blueprint $table): void {
            $table->dropUnique(['partial_payment_receipt_id']);
            $table->dropColumn('partial_payment_receipt_id');
        });
    }
};
