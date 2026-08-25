<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unique(['id', 'company_id'], 'branches_id_company_unique');
        });

        Schema::create('professionals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'user_id'], 'professionals_company_user_unique');
            $table->unique(['id', 'company_id'], 'professionals_id_company_unique');
            $table->index(['company_id', 'is_active'], 'professionals_company_active_index');
            $table->foreign(['company_id', 'user_id'], 'professionals_company_user_foreign')
                ->references(['company_id', 'user_id'])
                ->on('company_user')
                ->restrictOnDelete();
        });

        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'specialties_company_name_unique');
            $table->unique(['id', 'company_id'], 'specialties_id_company_unique');
            $table->index(['company_id', 'is_active'], 'specialties_company_active_index');
        });

        Schema::create('professional_branch', function (Blueprint $table) {
            $table->foreignId('company_id');
            $table->foreignId('professional_id');
            $table->foreignId('branch_id');
            $table->timestamps();

            $table->primary(['professional_id', 'branch_id']);
            $table->index(['company_id', 'branch_id'], 'professional_branch_company_branch_index');
            $table->foreign(['professional_id', 'company_id'], 'professional_branch_professional_foreign')
                ->references(['id', 'company_id'])
                ->on('professionals')
                ->cascadeOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'professional_branch_branch_foreign')
                ->references(['id', 'company_id'])
                ->on('branches')
                ->cascadeOnDelete();
        });

        Schema::create('professional_specialty', function (Blueprint $table) {
            $table->foreignId('company_id');
            $table->foreignId('professional_id');
            $table->foreignId('specialty_id');
            $table->timestamps();

            $table->primary(['professional_id', 'specialty_id']);
            $table->index(['company_id', 'specialty_id'], 'professional_specialty_company_specialty_index');
            $table->foreign(['professional_id', 'company_id'], 'professional_specialty_professional_foreign')
                ->references(['id', 'company_id'])
                ->on('professionals')
                ->cascadeOnDelete();
            $table->foreign(['specialty_id', 'company_id'], 'professional_specialty_specialty_foreign')
                ->references(['id', 'company_id'])
                ->on('specialties')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_specialty');
        Schema::dropIfExists('professional_branch');
        Schema::dropIfExists('specialties');
        Schema::dropIfExists('professionals');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_id_company_unique');
        });
    }
};
