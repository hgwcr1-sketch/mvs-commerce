<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->string('application_code_hash', 64)->nullable()->after('expires_at');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('credit_note_consumer_final')->default(false)->after('credit_note_custom_expiration_days');
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn('application_code_hash');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('credit_note_consumer_final');
        });
    }
};