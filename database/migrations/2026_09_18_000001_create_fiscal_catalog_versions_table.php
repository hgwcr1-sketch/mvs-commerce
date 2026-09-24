<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_catalog_versions', function (Blueprint $table) {
            $table->id();
            $table->string('source', 255);
            $table->string('source_version', 100);
            $table->timestamp('published_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->unique(['source', 'source_version']);
            $table->index(['status', 'valid_from', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_catalog_versions');
    }
};