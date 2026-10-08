<?php

namespace App\Support\Cobros;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Búsqueda de clientes CobrOS — fuente única usada por Mi cartera y por el
 * typeahead "Cliente existente".
 *
 * Normaliza acentos/caso del término y por cada palabra exige coincidencia en
 * nombre, comercial, identificación o teléfono (dígitos ignorando guiones y
 * espacios). Seguridad SIEMPRE por consulta (company + cartera según el llamador),
 * nunca solo en frontend.
 */
class CustomerSearch
{
    public static function apply(Builder $query, string $search, string $alias = 'customers'): Builder
    {
        $term = trim($search);
        if ($term === '') {
            return $query;
        }

        $tokens = preg_split('/\s+/u', Str::ascii($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_unique(array_filter($tokens, fn ($t) => $t !== '')));
        if ($tokens === []) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($query, $tokens, $alias) {
            foreach ($tokens as $token) {
                $outer->where(function (Builder $w) use ($token, $alias) {
                    foreach (['name', 'commercial_name', 'identification'] as $field) {
                        $w->orWhereRaw(self::folded("{$alias}.{$field}")." LIKE ? ESCAPE '!'", ['%'.self::escape($token).'%']);
                    }

                    // Teléfono: compara solo dígitos (tolera guiones/espacios).
                    $digits = preg_replace('/\D/', '', $token) ?? '';
                    if ($digits !== '') {
                        foreach (['phone', 'mobile'] as $field) {
                            $digitsExpr = "REPLACE(REPLACE(REPLACE(REPLACE({$alias}.{$field}, '-', ''), ' ', ''), '.', ''), '+', '')";
                            $w->orWhereRaw("{$digitsExpr} LIKE ?", ["%{$digits}%"]);
                        }
                    }
                });
            }
        });
    }

    /** Expresión SQL: minúsculas + acentos planos (SQLite compatible). */
    private static function folded(string $column): string
    {
        $expr = "LOWER({$column})";
        foreach (['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'] as $accent => $plain) {
            $expr = "REPLACE({$expr}, '{$accent}', '{$plain}')";
            $expr = "REPLACE({$expr}, '".mb_strtoupper($accent, 'UTF-8')."', '{$plain}')";
        }

        return $expr;
    }

    private static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
