<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_portal_credentials', function (Blueprint $table) {
            $table->timestamp('first_login_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_portal_credentials', function (Blueprint $table) {
            $table->dropColumn('first_login_at');
        });
    }
};
