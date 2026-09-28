<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('whatsapp_enabled')->default(false)->after('phone');
            $table->string('default_phone_country_code', 8)->nullable()->after('whatsapp_enabled');
            $table->string('whatsapp_phone_country_code', 8)->nullable()->after('default_phone_country_code');
            $table->string('whatsapp_phone', 30)->nullable()->after('whatsapp_phone_country_code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_enabled',
                'default_phone_country_code',
                'whatsapp_phone_country_code',
                'whatsapp_phone',
            ]);
        });
    }
};
