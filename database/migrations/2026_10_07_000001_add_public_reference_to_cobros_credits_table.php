<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 9C — referencia PÚBLICA única y no secuencial del préstamo (ej. CR-7K4M92).
 * Aditiva: agrega la columna con índice UNIQUE y rellena los créditos
 * existentes sin tocar ids/FKs, PDFs ni relaciones internas.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('cobros_credits', function (Blueprint $table) {
            $table->string('public_reference', 20)->nullable()->unique()->after('id');
        });

        $ids = DB::table('cobros_credits')->whereNull('public_reference')->pluck('id');
        foreach ($ids as $id) {
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $code = 'CR-'.strtoupper(Str::random(6));
                if (! preg_match('/[A-Z]/', $code) || ! preg_match('/[0-9]/', $code)) {
                    continue;
                }
                $exists = DB::table('cobros_credits')->where('public_reference', $code)->exists();
                if (! $exists) {
                    DB::table('cobros_credits')->where('id', $id)->update(['public_reference' => $code]);
                    break;
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('cobros_credits', function (Blueprint $table) {
            $table->dropUnique(['public_reference']);
            $table->dropColumn('public_reference');
        });
    }
};
