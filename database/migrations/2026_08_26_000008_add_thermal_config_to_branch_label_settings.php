<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_label_settings', function (Blueprint $table) {
            $table->string('default_print_mode', 10)->default('a4')->after('custom_heading');
            $table->boolean('use_custom_size')->default(false)->after('default_print_mode');
            $table->unsignedSmallInteger('custom_width')->default(50)->after('use_custom_size');
            $table->unsignedSmallInteger('custom_height')->default(30)->after('custom_width');
        });
    }

    public function down(): void
    {
        Schema::table('branch_label_settings', function (Blueprint $table) {
            $table->dropColumn(['default_print_mode', 'use_custom_size', 'custom_width', 'custom_height']);
        });
    }
};
