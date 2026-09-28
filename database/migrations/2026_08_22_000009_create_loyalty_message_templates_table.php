<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('opportunity_type', 30);
            $table->text('body');
            $table->timestamps();
            $table->unique(['company_id', 'opportunity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_message_templates');
    }
};
