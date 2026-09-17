<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('layaways', function (Blueprint $table) {
            $table->uuid('client_token')->nullable()->after('number');
            $table->string('request_fingerprint', 64)->nullable()->after('client_token');

            $table->unique(['company_id', 'client_token']);
        });
    }

    public function down(): void
    {
        Schema::table('layaways', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'client_token']);
            $table->dropColumn(['client_token', 'request_fingerprint']);
        });
    }
};
