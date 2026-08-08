<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            $table->string('phone', 30)->nullable()->after('email');

            $table->string('photo')->nullable()->after('phone');

            $table->boolean('is_active')
                ->default(true)
                ->after('password');

            $table->timestamp('last_login_at')
                ->nullable()
                ->after('remember_token');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {

            $table->dropColumn([
                'phone',
                'photo',
                'is_active',
                'last_login_at',
            ]);

        });
    }
};