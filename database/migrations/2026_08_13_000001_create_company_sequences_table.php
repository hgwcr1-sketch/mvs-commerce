<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('name', 50);
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();

            $table->unique(
                ['company_id', 'name'],
                'company_sequences_company_name_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_sequences');
    }
};
