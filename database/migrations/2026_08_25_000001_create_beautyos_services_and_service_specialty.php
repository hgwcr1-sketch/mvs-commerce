<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('duration_minutes');
            $table->decimal('price', 19, 4);
            $table->decimal('estimated_cost', 19, 4)->default(0);
            $table->unsignedInteger('preparation_minutes')->default(0);
            $table->unsignedInteger('buffer_before_minutes')->default(0);
            $table->unsignedInteger('buffer_after_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'services_company_name_unique');
            $table->unique(['id', 'company_id'], 'services_id_company_unique');
            $table->index(['company_id', 'is_active'], 'services_company_active_index');
        });

        Schema::create('service_specialty', function (Blueprint $table) {
            $table->foreignId('company_id');
            $table->foreignId('service_id');
            $table->foreignId('specialty_id');
            $table->timestamps();

            $table->primary(['service_id', 'specialty_id']);
            $table->index(['company_id', 'specialty_id'], 'service_specialty_company_specialty_index');
            $table->foreign(['service_id', 'company_id'], 'service_specialty_service_foreign')
                ->references(['id', 'company_id'])
                ->on('services')
                ->cascadeOnDelete();
            $table->foreign(['specialty_id', 'company_id'], 'service_specialty_specialty_foreign')
                ->references(['id', 'company_id'])
                ->on('specialties')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_specialty');
        Schema::dropIfExists('services');
    }
};
