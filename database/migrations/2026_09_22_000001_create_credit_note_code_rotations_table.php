<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoría persistente de regeneraciones de código de NC Consumer Final
     * (Fase 4B-4).
     *
     * NO almacena secretos: ni old_code, ni new_code, ni old_hash ni new_hash.
     * Solo registra que ocurrió la rotación, quién la hizo, cuándo y el motivo.
     */
    public function up(): void
    {
        Schema::create('credit_note_code_rotations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->foreignId('credit_note_id')
                ->constrained('credit_notes')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('reason', 500);

            $table->timestamps();

            $table->index('company_id', 'credit_note_code_rotations_company_index');
            $table->index('credit_note_id', 'credit_note_code_rotations_note_index');
            $table->index(
                ['company_id', 'credit_note_id'],
                'credit_note_code_rotations_company_note_index'
            );
            $table->index(
                ['credit_note_id', 'created_at'],
                'credit_note_code_rotations_note_created_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_code_rotations');
    }
};