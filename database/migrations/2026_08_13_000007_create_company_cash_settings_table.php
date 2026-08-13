<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_cash_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->boolean('require_open_session')->default(false);
            $table->boolean('allow_multiple_registers')->default(false);
            $table->string('session_mode', 20)->default('individual');
            $table->decimal('difference_tolerance', 19, 4)->default(0);
            $table->boolean('require_difference_authorization')->default(false);
            $table->boolean('auto_print_closure')->default(false);
            $table->boolean('blind_closing')->default(true);
            $table->boolean('accepts_usd')->default(false);
            $table->decimal('usd_exchange_rate_min', 19, 4)->nullable();
            $table->decimal('usd_exchange_rate_max', 19, 4)->nullable();
            $table->string('usd_change_policy', 20)->default('crc_only');
            $table->json('closure_email_recipients')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_cash_settings');
    }
};
