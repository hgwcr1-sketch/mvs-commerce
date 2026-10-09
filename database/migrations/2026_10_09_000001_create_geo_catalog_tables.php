<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_catalog_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('name', 80);
            $table->unsignedTinyInteger('parent_level')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['country_id', 'level']);
        });

        Schema::create('geo_divisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('geo_divisions')->nullOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('catalog_version', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['country_id', 'code']);
            $table->index(['country_id', 'level', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_divisions');
        Schema::dropIfExists('geo_catalog_levels');
    }
};
