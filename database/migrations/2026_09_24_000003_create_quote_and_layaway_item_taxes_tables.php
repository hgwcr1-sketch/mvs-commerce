<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_item_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_item_id')->constrained()->cascadeOnDelete();
            $table->string('tax_code', 2);
            $table->string('tax_rate_code', 2)->nullable();
            $table->string('description', 160)->nullable();
            $table->string('treatment', 40)->nullable();
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('factor_iva', 8, 6)->nullable();
            $table->decimal('base_amount', 19, 4)->nullable();
            $table->decimal('tax_amount', 19, 4)->nullable();
            $table->json('specific_tax_data')->nullable();
            $table->json('exemption_snapshot')->nullable();
            $table->string('source', 255)->nullable();
            $table->string('source_version', 100)->nullable();
            $table->unsignedInteger('sequence')->default(1);
            $table->timestamps();

            $table->index(['quote_item_id', 'sequence']);
        });

        Schema::create('layaway_item_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layaway_item_id')->constrained()->cascadeOnDelete();
            $table->string('tax_code', 2);
            $table->string('tax_rate_code', 2)->nullable();
            $table->string('description', 160)->nullable();
            $table->string('treatment', 40)->nullable();
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('factor_iva', 8, 6)->nullable();
            $table->decimal('base_amount', 19, 4)->nullable();
            $table->decimal('tax_amount', 19, 4)->nullable();
            $table->json('specific_tax_data')->nullable();
            $table->json('exemption_snapshot')->nullable();
            $table->string('source', 255)->nullable();
            $table->string('source_version', 100)->nullable();
            $table->unsignedInteger('sequence')->default(1);
            $table->timestamps();

            $table->index(['layaway_item_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('layaway_item_taxes');
        Schema::dropIfExists('quote_item_taxes');
    }
};
