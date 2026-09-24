<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_visits', function (Blueprint $table) {
            $table->decimal('latitude', 10, 8)->nullable()->after('promise_amount');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->timestamp('location_captured_at')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('cobros_visits', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'location_captured_at']);
        });
    }
};
