<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('prints_label')->default(false)->after('is_active');
        });

        Schema::create('branch_label_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->json('print_destinations')->nullable();
            $table->string('default_template', 40)->default('name_price_barcode');
            $table->string('default_size', 20)->default('50x30');
            $table->string('custom_heading', 80)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_label_settings');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('prints_label');
        });
    }
};
