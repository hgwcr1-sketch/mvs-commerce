<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_catalog_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('source', 255);
            $table->string('source_url', 255)->nullable();
            $table->string('version', 60);
            $table->date('source_date')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->unsignedInteger('division_count')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['country_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_catalog_sources');
    }
};
