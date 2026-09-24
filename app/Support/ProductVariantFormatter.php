<?php

namespace App\Support;

use App\Models\Product;

class ProductVariantFormatter
{
    /**
     * Devuelve un string legible con las variantes de un producto.
     *
     * Ejemplo: "Estilo: Casual / Talla: M / Color: Azul"
     * Omite las variantes que no tengan valor.
     */
    public static function label(?Product $product): ?string
    {
        if ($product === null) {
            return null;
        }

        $parts = [];

        if ($product->style?->name) {
            $parts[] = 'Estilo: '.$product->style->name;
        }

        if ($product->size?->name) {
            $parts[] = 'Talla: '.$product->size->name;
        }

        if ($product->color?->name) {
            $parts[] = 'Color: '.$product->color->name;
        }

        return $parts === [] ? null : implode(' / ', $parts);
    }

    /**
     * Devuelve un array plano con las variantes presentes.
     *
     * Útil para serializar JSON sin objetos anidados.
     */
    public static function array(?Product $product): array
    {
        if ($product === null) {
            return [];
        }

        return array_filter([
            'style_name' => $product->style?->name,
            'size_name' => $product->size?->name,
            'color_name' => $product->color?->name,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
