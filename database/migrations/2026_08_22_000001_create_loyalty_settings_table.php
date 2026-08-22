<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(false);
            $table->decimal('earning_percentage', 9, 4)->default(0);
            $table->decimal('point_value', 19, 4)->default(1);
            $table->decimal('minimum_redemption_points', 19, 4)->default(0);
            $table->decimal('maximum_redemption_percent', 9, 4)->default(100);
            $table->boolean('earn_on_offers')->default(false);
            $table->boolean('redeem_on_offers')->default(false);
            $table->boolean('expiration_enabled')->default(false);
            $table->unsignedInteger('expiration_months')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_settings');
    }
};
