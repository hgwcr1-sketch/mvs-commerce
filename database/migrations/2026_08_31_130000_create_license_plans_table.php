<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 80);
            $table->unsignedInteger('branch_limit')->nullable();
            $table->unsignedInteger('user_limit')->nullable();
            $table->json('modules');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('company_licenses', function (Blueprint $table) {
            $table->foreignId('license_plan_id')->nullable()->after('company_id')->constrained('license_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_licenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('license_plan_id');
        });
        Schema::dropIfExists('license_plans');
    }
};
