<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_settings', function (Blueprint $table) {
            $table->boolean('birthday_enabled')->default(false)->after('earn_on_offers');
            $table->decimal('birthday_points', 19, 4)->default(0)->after('birthday_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_settings', function (Blueprint $table) {
            $table->dropColumn(['birthday_enabled', 'birthday_points']);
        });
    }
};
