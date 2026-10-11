<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_demo_sessions', function (Blueprint $table) {
            $table->text('demo_payment')->nullable()->after('tour_answers');
        });
    }

    public function down(): void
    {
        Schema::table('cobros_demo_sessions', function (Blueprint $table) {
            $table->dropColumn('demo_payment');
        });
    }
};
