<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_portal_posts', function (Blueprint $table) {
            $table->string('cta_type', 30)->nullable()->after('message');
            $table->string('cta_url', 500)->nullable()->after('cta_type');
        });

        Schema::table('loyalty_portal_settings', function (Blueprint $table) {
            $table->string('portal_name', 120)->nullable()->after('show_active_offers');
            $table->string('portal_logo')->nullable()->after('welcome_message');
            $table->string('portal_icon')->nullable()->after('portal_logo');
            $table->string('instagram_url', 500)->nullable()->after('brand_accent_color');
            $table->string('facebook_url', 500)->nullable()->after('instagram_url');
            $table->string('tiktok_url', 500)->nullable()->after('facebook_url');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_portal_settings', function (Blueprint $table) {
            $table->dropColumn([
                'portal_name',
                'portal_logo',
                'portal_icon',
                'instagram_url',
                'facebook_url',
                'tiktok_url',
            ]);
        });

        Schema::table('loyalty_portal_posts', function (Blueprint $table) {
            $table->dropColumn(['cta_type', 'cta_url']);
        });
    }
};
