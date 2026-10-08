<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Identidad GLOBAL CobrOS/Buró: sin company_id, dedupe por país+tipo+ID.
        Schema::create('cobros_bureau_identities', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2);
            $table->string('identification_type', 20);
            $table->string('normalized_identification', 64);
            $table->timestamps();

            $table->unique(
                ['country_code', 'identification_type', 'normalized_identification'],
                'bureau_identity_unique'
            );
        });

        // Evidencias: REFERENCIA al storage privado ya usado por 7A (sin copia física).
        Schema::create('cobros_bureau_identity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bureau_identity_id')->constrained('cobros_bureau_identities')->restrictOnDelete();
            $table->foreignId('source_credit_request_id')->constrained('cobros_credit_requests')->restrictOnDelete();
            $table->foreignId('source_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('source_collector_user_id')->constrained('users')->restrictOnDelete();
            $table->string('document_type', 10); // front | back
            $table->string('private_path', 255);
            $table->timestamp('uploaded_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['source_credit_request_id', 'document_type'], 'bureau_doc_request_type_unique');
            $table->index(['source_company_id', 'bureau_identity_id']);
        });

        // Bitácora append-only de Buró (sin updated_at; sin DELETE en servicios).
        Schema::create('cobros_bureau_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bureau_identity_id')->constrained('cobros_bureau_identities')->restrictOnDelete();
            $table->string('event_type', 40);
            $table->foreignId('source_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignId('source_credit_request_id')->nullable()->constrained('cobros_credit_requests')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('purpose', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['bureau_identity_id', 'event_type']);
            $table->index(['source_company_id', 'occurred_at']);
        });

        Schema::table('cobros_credit_requests', function (Blueprint $table) {
            $table->foreignId('bureau_identity_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('cobros_bureau_identities')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cobros_credit_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bureau_identity_id');
        });
        Schema::dropIfExists('cobros_bureau_events');
        Schema::dropIfExists('cobros_bureau_identity_documents');
        Schema::dropIfExists('cobros_bureau_identities');
    }
};
