<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('payable_alert_days')->default(5);
        });

        Schema::create('account_payable_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_payable_id')->constrained('accounts_payable')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->timestamp('notified_at');
            $table->timestamps();
            $table->unique(['account_payable_id', 'type'], 'ap_alerts_account_type_unique');
            $table->index(['company_id', 'type', 'notified_at'], 'ap_alerts_company_type_notified_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_payable_alerts');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('payable_alert_days');
        });
    }
};
