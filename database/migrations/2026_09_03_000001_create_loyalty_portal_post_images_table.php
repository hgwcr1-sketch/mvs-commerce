<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_portal_post_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_portal_post_id')->constrained('loyalty_portal_posts')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'loyalty_portal_post_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_portal_post_images');
    }
};
