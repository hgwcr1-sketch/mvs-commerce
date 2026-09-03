<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_migration_manual_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('source_key', 100);
            $table->unsignedInteger('row_number');
            $table->string('normalized_name');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['company_id', 'source_key', 'row_number', 'normalized_name'],
                'loyalty_migration_manual_resolutions_row_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_migration_manual_resolutions');
    }
};
