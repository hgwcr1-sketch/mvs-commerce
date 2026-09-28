<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->string('availability_mode', 20)->default('unlimited')->after('type');
            $table->foreignId('product_id')->nullable()->after('description')->constrained('products')->restrictOnDelete();
            $table->decimal('stock_quantity', 15, 4)->nullable()->after('availability_mode');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['availability_mode', 'stock_quantity']);
        });
    }
};
