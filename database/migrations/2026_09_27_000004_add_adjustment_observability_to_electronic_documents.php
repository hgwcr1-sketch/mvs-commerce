<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->string('source_type', 30)->nullable()->after('sale_id');
            $table->string('source_id', 60)->nullable()->after('source_type');
            $table->foreignId('original_document_id')->nullable()->after('source_id')->constrained('electronic_documents')->nullOnDelete();
            $table->index(['company_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('original_document_id');
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
