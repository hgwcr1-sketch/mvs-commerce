<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_demo_sessions', function (Blueprint $table) {
            $table->string('tour_step', 40)->nullable()->after('last_seen_at');
            $table->text('tour_answers')->nullable()->after('tour_step');
        });
    }

    public function down(): void
    {
        Schema::table('cobros_demo_sessions', function (Blueprint $table) {
            $table->dropColumn(['tour_step', 'tour_answers']);
        });
    }
};
