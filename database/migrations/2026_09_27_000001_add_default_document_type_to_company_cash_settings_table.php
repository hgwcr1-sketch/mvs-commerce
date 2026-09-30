<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_cash_settings', function (Blueprint $table) {
            // NULL = sin configurar: el resolver aplica el default seguro 'ticket'.
            $table->string('default_document_type', 30)->nullable()->after('closure_email_recipients');
        });
    }

    public function down(): void
    {
        Schema::table('company_cash_settings', function (Blueprint $table) {
            $table->dropColumn('default_document_type');
        });
    }
};
