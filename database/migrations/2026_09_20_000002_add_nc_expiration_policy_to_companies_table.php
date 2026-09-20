<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('credit_note_expiration_policy', 20)->default('none')->after('timezone');
            $table->unsignedSmallInteger('credit_note_custom_expiration_days')->nullable()->after('credit_note_expiration_policy');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['credit_note_expiration_policy', 'credit_note_custom_expiration_days']);
        });
    }
};
