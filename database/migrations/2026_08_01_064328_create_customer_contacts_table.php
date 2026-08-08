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
        Schema::create('customer_contacts', function (Blueprint $table) {

    $table->id();

    $table->foreignId('customer_id')
        ->constrained('customers')
        ->cascadeOnDelete();

    $table->string('name', 150);

    $table->string('position', 100)->nullable();

    $table->string('phone', 30)->nullable();

    $table->string('mobile', 30)->nullable();

    $table->string('email', 150)->nullable();

    $table->boolean('is_primary')->default(false);

    $table->text('notes')->nullable();

    $table->timestamps();

});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
    }
};
