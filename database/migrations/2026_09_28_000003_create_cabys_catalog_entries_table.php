<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo CABYS local y versionado (MF04 adaptado a producción).
 *
 * NO se toca la tabla `cabys` heredada: sigue vacía y con un importador que
 * apunta a columnas inexistentes. El catálogo operativo vive aquí.
 *
 * `code` es texto y SIEMPRE con ceros iniciales: si se tratara como número,
 * `0111100000100` dejaría de existir.
 *
 * `tax_rate_raw` guarda el texto EXACTO de la fuente ("13%", "1%",
 * "Exento"); `tax_rate_pct` es su traducción numérica y queda NULL cuando el
 * texto no es inequívoco. Nunca se rellena con 13 por defecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cabys_catalog_entries', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('fiscal_catalog_version_id')
                ->constrained('fiscal_catalog_versions')
                ->restrictOnDelete();

            $table->string('code', 20);
            $table->text('description');

            for ($level = 1; $level <= 9; $level++) {
                $table->string("category{$level}_code", 20)->nullable();
                $table->text("category{$level}_description")->nullable();
            }

            $table->string('tax_rate_raw', 40)->nullable();
            $table->decimal('tax_rate_pct', 5, 2)->nullable();

            $table->text('note_include')->nullable();
            $table->text('note_exclude')->nullable();

            $table->boolean('is_active')->default(true);

            /** Orden del archivo oficial: conserva la jerarquía publicada. */
            $table->unsignedInteger('position')->nullable();

            $table->unique(['fiscal_catalog_version_id', 'code'], 'cabys_entries_version_code_unique');
            $table->index('code', 'cabys_entries_code_index');
            $table->index(['fiscal_catalog_version_id', 'is_active'], 'cabys_entries_version_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabys_catalog_entries');
    }
};
