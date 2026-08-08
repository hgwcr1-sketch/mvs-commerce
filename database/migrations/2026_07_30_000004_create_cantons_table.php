<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cantons', function (Blueprint $table) {

            $table->id();

            $table->foreignId('province_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('name',150);

            $table->string('code',20)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique([
                'province_id',
                'name'
            ]);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cantons');
    }
};