<?php

namespace App\Services\Imports;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class PurchaseExcelImport
{
    private const HEADERS = [
        'codigo' => 'code',
        'codigo barra' => 'barcode',
        'codigo barras' => 'barcode',
        'producto' => 'name',
        'descripcion' => 'description',
        'categoria' => 'category',
        'marca' => 'brand',
        'proveedor' => 'supplier',
        'unidad' => 'unit',
        'unidad de medida' => 'unit',
        'tipo articulo' => 'product_type',
        'cantidad' => 'quantity',
        'costo' => 'cost',
        'precio' => 'new_sale_price',
        'precio venta' => 'new_sale_price',
        'precio de venta' => 'new_sale_price',
        'impuesto' => 'tax_rate',
        'impuesto %' => 'tax_rate',
        'descuento' => 'discount_percent',
        'descuento %' => 'discount_percent',
        'cabys' => 'cabys',
        'minimo stock' => 'minimum_stock',
        'stock minimo' => 'minimum_stock',
        'maximo stock' => 'maximum_stock',
        'stock maximo' => 'maximum_stock',
        'lote' => 'lot_number',
        'fecha vencimiento' => 'expires_at',
        'fecha de vencimiento' => 'expires_at',
    ];

    private const FIELDS = [
        'code', 'barcode', 'name', 'description', 'category', 'brand',
        'supplier', 'unit', 'product_type', 'quantity', 'cost',
        'new_sale_price', 'tax_rate', 'discount_percent', 'cabys',
        'minimum_stock', 'maximum_stock', 'lot_number', 'expires_at',
    ];

    private const NUMERIC_FIELDS = [
        'quantity', 'cost', 'new_sale_price', 'tax_rate',
        'discount_percent', 'minimum_stock', 'maximum_stock',
    ];

    public function read(string $file): array
    {
        $sheetRows = IOFactory::load($file)
            ->getActiveSheet()
            ->toArray(null, true, true, false);

        if ($sheetRows === []) {
            return [];
        }

        $columns = $this->resolveColumns(array_shift($sheetRows));
        $rows = [];

        foreach ($sheetRows as $index => $sourceRow) {
            $row = [];

            foreach (self::FIELDS as $field) {
                $column = $columns[$field] ?? null;
                $value = $column === null
                    ? null
                    : $this->nullableValue($sourceRow[$column] ?? null);

                if ($value !== null && in_array($field, self::NUMERIC_FIELDS, true)) {
                    $value = (float) $value;
                }

                if ($field === 'expires_at') {
                    $value = $this->normalizeDate($value);
                }

                if ($field === 'category') {
                    $value = $this->normalizeCategoryPath($value);
                }

                $row[$field] = $value;
            }

            if (collect($row)->every(fn ($value) => $value === null)) {
                continue;
            }

            $row['_row_key'] = 'excel-' . ($index + 2);
            $rows[] = $row;
        }

        return $rows;
    }

    private function resolveColumns(array $headers): array
    {
        $columns = [];

        foreach ($headers as $column => $header) {
            $field = self::HEADERS[$this->normalizeHeader($header)] ?? null;

            if ($field !== null) {
                $columns[$field] = $column;
            }
        }

        return $columns;
    }

    private function normalizeHeader(mixed $header): string
    {
        return Str::of((string) $header)
            ->ascii()
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->replaceMatches('/\s*\*+\s*$/', '')
            ->trim()
            ->toString();
    }

    private function nullableValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return trim((string) $value);
    }

    private function normalizeCategoryPath(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === ''
            ? null
            : preg_replace('/\s*>\s*/', ' > ', $value);
    }
}
