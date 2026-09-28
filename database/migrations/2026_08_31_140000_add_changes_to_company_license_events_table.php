<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_license_events', function (Blueprint $table) {
            $table->json('changes')->nullable()->after('snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('company_license_events', function (Blueprint $table) {
            $table->dropColumn('changes');
        });
    }
};
