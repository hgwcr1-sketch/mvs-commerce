<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class HistoricalSaleImportService
{
    public const HEADERS = [
        'numero_documento*', 'fecha*', 'codigo_sucursal*', 'tipo_documento*', 'condicion_venta*',
        'moneda*', 'tipo_cambio*', 'identificacion_cliente', 'subtotal_documento*',
        'descuento_documento*', 'impuesto_documento*', 'total_documento*', 'numero_linea*',
        'codigo_producto', 'codigo_barras', 'descripcion*', 'cantidad*', 'precio_unitario*',
        'descuento_linea*', 'tasa_impuesto*', 'costo_unitario',
    ];

    private const MAP = [
        'numero_documento' => 'sale_number', 'fecha' => 'completed_at', 'codigo_sucursal' => 'branch_code',
        'tipo_documento' => 'document_type', 'condicion_venta' => 'sale_condition', 'moneda' => 'currency_code',
        'tipo_cambio' => 'exchange_rate', 'identificacion_cliente' => 'customer_identification',
        'subtotal_documento' => 'document_subtotal', 'descuento_documento' => 'document_discount',
        'impuesto_documento' => 'document_tax', 'total_documento' => 'document_total',
        'numero_linea' => 'line_number', 'codigo_producto' => 'product_code', 'codigo_barras' => 'barcode',
        'descripcion' => 'description', 'cantidad' => 'quantity', 'precio_unitario' => 'unit_price',
        'descuento_linea' => 'line_discount', 'tasa_impuesto' => 'tax_rate', 'costo_unitario' => 'unit_cost',
    ];

    private const LABELS = [
        'completed_at' => 'fecha', 'branch_id' => 'codigo_sucursal', 'document_type' => 'tipo_documento',
        'sale_condition' => 'condicion_venta', 'currency_code' => 'moneda', 'exchange_rate' => 'tipo_cambio',
        'customer_id' => 'identificacion_cliente', 'document_subtotal' => 'subtotal_documento',
        'document_discount' => 'descuento_documento', 'document_tax' => 'impuesto_documento',
        'document_total' => 'total_documento', 'quantity' => 'cantidad', 'unit_price' => 'precio_unitario',
        'line_discount' => 'descuento_linea', 'tax_rate' => 'tasa_impuesto', 'unit_cost' => 'costo_unitario',
    ];

    public function preview(string $path, int $companyId): array
    {
        $source = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        if (count($source) < 2) {
            throw ValidationException::withMessages(['sales_file' => 'El archivo debe incluir encabezados y al menos una línea.']);
        }
        $headers = $this->headers(array_shift($source));
        $rows = [];
        foreach ($source as $offset => $values) {
            if (collect($values)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }
            $data = [];
            foreach ($headers as $column => $field) {
                if ($field !== null) {
                    $data[$field] = $values[$column] ?? null;
                }
            }
            $rows[] = $this->normalize($data, $offset + 2, $companyId);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['sales_file' => 'El archivo no contiene líneas para revisar.']);
        }

        return $this->validate($rows, $companyId);
    }

    public function confirm(array $preview, int $companyId, int $userId): int
    {
        if ((int) ($preview['company_id'] ?? 0) !== $companyId) {
            throw ValidationException::withMessages(['sales_file' => 'La vista previa no pertenece a la empresa activa.']);
        }
        $rows = $this->validate($preview['rows'] ?? [], $companyId);
        if ($invalid = collect($rows)->firstWhere('valid', false)) {
            $error = $invalid['errors'][0] ?? ['field' => 'documento', 'message' => 'dato inválido'];
            throw ValidationException::withMessages([
                'sales_file' => "La importación cambió o contiene errores. Fila {$invalid['row_number']}, {$error['field']}: {$error['message']}",
            ]);
        }

        return DB::transaction(function () use ($rows, $companyId, $userId): int {
            $documents = collect($rows)->groupBy('sale_number');
            foreach ($documents as $number => $lines) {
                $first = $lines->first();
                $sale = Sale::create([
                    'company_id' => $companyId, 'branch_id' => $first['branch_id'], 'user_id' => $userId,
                    'cash_session_id' => null, 'customer_id' => $first['customer_id'], 'sale_number' => $number,
                    'document_type' => $first['document_type'], 'sale_condition' => $first['sale_condition'],
                    'status' => Sale::STATUS_COMPLETED, 'is_historical' => true,
                    'currency_code' => $first['currency_code'], 'exchange_rate' => $first['exchange_rate'],
                    'subtotal' => $first['document_subtotal'], 'discount_total' => $first['document_discount'],
                    'tax_total' => $first['document_tax'], 'rounding_total' => '0.0000', 'total' => $first['document_total'],
                    'paid_total' => $first['document_total'], 'balance_due' => '0.0000', 'due_date' => null,
                    'notes' => 'Importación histórica P34/P35; sin efectos operativos.', 'completed_at' => $first['completed_at'],
                ]);
                foreach ($lines as $line) {
                    SaleItem::create([
                        'sale_id' => $sale->id, 'product_id' => $line['product_id'], 'product_code' => $line['product_code'],
                        'barcode' => $line['barcode'], 'cabys_code' => $line['cabys_code'], 'description' => $line['description'],
                        'unit_code' => $line['unit_code'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'],
                        'gross_total' => $line['gross_total'], 'discount_total' => $line['line_discount'],
                        'subtotal' => $line['line_subtotal'], 'tax_rate' => $line['tax_rate'],
                        'tax_total' => $line['line_tax'], 'total' => $line['line_total'], 'unit_cost' => $line['unit_cost'],
                    ]);
                }
            }

            return $documents->count();
        });
    }

    private function headers(array $headers): array
    {
        $resolved = [];
        foreach ($headers as $column => $header) {
            $key = trim(Str::of((string) $header)->ascii()->lower()->replace([' ', '-', '*'], ['_', '_', ''])->toString(), '_');
            $resolved[$column] = self::MAP[$key] ?? null;
        }
        foreach (array_values(self::MAP) as $required) {
            if ($required !== 'customer_identification' && $required !== 'product_code' && $required !== 'barcode' && $required !== 'unit_cost' && ! in_array($required, $resolved, true)) {
                throw ValidationException::withMessages(['sales_file' => 'Falta una columna obligatoria. Descargue la plantilla vigente.']);
            }
        }

        return $resolved;
    }

    private function normalize(array $data, int $rowNumber, int $companyId): array
    {
        $branchCode = $this->text($data['branch_code'] ?? null);
        $customerIdentification = $this->text($data['customer_identification'] ?? null);
        $productCode = $this->text($data['product_code'] ?? null);
        $barcode = $this->text($data['barcode'] ?? null);
        $branch = Branch::query()->where('company_id', $companyId)->where('code', $branchCode)->first();
        $customer = $customerIdentification === null ? null : Customer::withTrashed()->where('company_id', $companyId)->where('identification', $customerIdentification)->first();
        $byCode = $productCode === null ? null : Product::withTrashed()->where('company_id', $companyId)->where('internal_code', $productCode)->first();
        $byBarcode = $barcode === null ? null : Product::withTrashed()->where('company_id', $companyId)->where(function ($query) use ($barcode) {
            $query->where('barcode', $barcode)->orWhereHas('barcodes', fn ($q) => $q->where('barcode', $barcode));
        })->first();
        $product = $byCode ?? $byBarcode;
        $quantity = $this->decimal($data['quantity'] ?? null);
        $unitPrice = $this->decimal($data['unit_price'] ?? null);
        $discount = $this->decimal($data['line_discount'] ?? null);
        $taxRate = $this->decimal($data['tax_rate'] ?? null);
        $gross = $this->math($quantity, $unitPrice, 'mul');
        $subtotal = $this->math($gross, $discount, 'sub');
        $tax = $this->math($subtotal, $this->math($taxRate, '100.0000', 'div'), 'mul');

        return [
            'row_number' => $rowNumber, 'sale_number' => $this->text($data['sale_number'] ?? null),
            'completed_at' => $this->date($data['completed_at'] ?? null), 'branch_code' => $branchCode, 'branch_id' => $branch?->id,
            'document_type' => $this->documentType($data['document_type'] ?? null),
            'sale_condition' => $this->saleCondition($data['sale_condition'] ?? null),
            'currency_code' => Str::upper($this->text($data['currency_code'] ?? null) ?? ''),
            'exchange_rate' => $this->decimal($data['exchange_rate'] ?? null),
            'customer_identification' => $customerIdentification, 'customer_id' => $customer?->id,
            'document_subtotal' => $this->decimal($data['document_subtotal'] ?? null),
            'document_discount' => $this->decimal($data['document_discount'] ?? null),
            'document_tax' => $this->decimal($data['document_tax'] ?? null), 'document_total' => $this->decimal($data['document_total'] ?? null),
            'line_number' => $this->text($data['line_number'] ?? null), 'product_code' => $productCode,
            'barcode' => $barcode, 'product_id' => $product?->id, 'product_conflict' => $byCode && $byBarcode && $byCode->id !== $byBarcode->id,
            'description' => $this->text($data['description'] ?? null), 'quantity' => $quantity, 'unit_price' => $unitPrice,
            'line_discount' => $discount, 'tax_rate' => $taxRate, 'unit_cost' => $this->decimal($data['unit_cost'] ?? null) ?? '0.0000',
            'gross_total' => $gross, 'line_subtotal' => $subtotal, 'line_tax' => $tax,
            'line_total' => $this->math($subtotal, $tax, 'add'), 'cabys_code' => $product?->cabys_code,
            'unit_code' => $product?->unit?->abbreviation, 'valid' => true, 'errors' => [],
        ];
    }

    private function validate(array $rows, int $companyId): array
    {
        $documents = collect($rows)->groupBy('sale_number');
        foreach ($documents as $number => $documentRows) {
            $first = $documentRows->first();
            $documentErrors = [];
            if (! $number) {
                $documentErrors[] = ['field' => 'numero_documento', 'message' => 'Es obligatorio.'];
            }
            if ($number && Sale::query()->where('company_id', $companyId)->where('sale_number', $number)->exists()) {
                $documentErrors[] = ['field' => 'numero_documento', 'message' => 'El documento ya existe; no se importará dos veces.'];
            }
            foreach (['completed_at', 'branch_id', 'document_type', 'sale_condition'] as $field) {
                if (! $first[$field]) {
                    $documentErrors[] = ['field' => self::LABELS[$field] ?? $field, 'message' => 'El valor es inválido o no pertenece a la empresa.'];
                }
            }
            if (! preg_match('/^[A-Z]{3}$/', $first['currency_code'])) {
                $documentErrors[] = ['field' => 'moneda', 'message' => 'Use un código ISO de tres letras.'];
            }
            if ($first['customer_identification'] !== null && $first['customer_id'] === null) {
                $documentErrors[] = ['field' => 'identificacion_cliente', 'message' => 'El cliente no existe en la empresa activa.'];
            }
            foreach ($documentRows as $row) {
                foreach (['branch_id', 'document_type', 'sale_condition', 'currency_code', 'exchange_rate', 'customer_id', 'document_subtotal', 'document_discount', 'document_tax', 'document_total', 'completed_at'] as $field) {
                    if (($row[$field] ?? null) !== ($first[$field] ?? null)) {
                        $documentErrors[] = ['field' => self::LABELS[$field] ?? $field, 'message' => 'El dato de encabezado cambia entre líneas del mismo documento.'];
                    }
                }
            }
            $sum = fn (string $field) => $documentRows->reduce(fn ($carry, $row) => bcadd($carry, $row[$field] ?? '0', 4), '0.0000');
            foreach (['document_subtotal' => $sum('line_subtotal'), 'document_discount' => $sum('line_discount'), 'document_tax' => $sum('line_tax'), 'document_total' => $sum('line_total')] as $field => $expected) {
                if (! $this->equal($first[$field], $expected)) {
                    $documentErrors[] = ['field' => self::LABELS[$field] ?? $field, 'message' => "No concilia con las líneas; esperado {$expected}."];
                }
            }
            $seenLines = [];
            foreach ($documentRows as $row) {
                $errors = $documentErrors;
                if (! $row['line_number'] || isset($seenLines[$row['line_number']])) {
                    $errors[] = ['field' => 'numero_linea', 'message' => 'Es obligatorio y único dentro del documento.'];
                }
                $seenLines[$row['line_number']] = true;
                if (! $row['product_code'] && ! $row['barcode']) {
                    $errors[] = ['field' => 'producto', 'message' => 'Indique código de producto o código de barras.'];
                }
                if (! $row['product_id']) {
                    $errors[] = ['field' => 'producto', 'message' => 'El producto no existe en la empresa activa.'];
                }
                if ($row['product_conflict']) {
                    $errors[] = ['field' => 'producto', 'message' => 'El código y el código de barras corresponden a productos distintos.'];
                }
                if (! $row['description']) {
                    $errors[] = ['field' => 'descripcion', 'message' => 'Es obligatoria.'];
                }
                foreach (['exchange_rate', 'document_subtotal', 'document_discount', 'document_tax', 'document_total', 'quantity', 'unit_price', 'line_discount', 'tax_rate', 'unit_cost'] as $field) {
                    if (! $this->validDecimal($row[$field])) {
                        $errors[] = ['field' => self::LABELS[$field] ?? $field, 'message' => 'Use un decimal no negativo con máximo cuatro decimales.'];
                    }
                }
                if ($this->validDecimal($row['quantity']) && bccomp($row['quantity'], '0', 4) <= 0) {
                    $errors[] = ['field' => 'cantidad', 'message' => 'Debe ser mayor que cero.'];
                }
                if ($this->validDecimal($row['exchange_rate']) && bccomp($row['exchange_rate'], '0', 4) <= 0) {
                    $errors[] = ['field' => 'tipo_cambio', 'message' => 'Debe ser mayor que cero.'];
                }
                if ($this->validDecimal($row['tax_rate']) && bccomp($row['tax_rate'], '100', 4) > 0) {
                    $errors[] = ['field' => 'tasa_impuesto', 'message' => 'No puede superar 100.'];
                }
                if ($this->validDecimal($row['line_discount']) && $this->validDecimal($row['gross_total']) && bccomp($row['line_discount'], $row['gross_total'], 4) > 0) {
                    $errors[] = ['field' => 'descuento_linea', 'message' => 'No puede superar el bruto de línea.'];
                }
                $row['errors'] = collect($errors)->unique(fn ($error) => $error['field'].'|'.$error['message'])->values()->all();
                $row['valid'] = $row['errors'] === [];
                $rows[array_search($row['row_number'], array_column($rows, 'row_number'), true)] = $row;
            }
        }

        return $rows;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function decimal(mixed $value): ?string
    {
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return $this->text($value);
    }

    private function validDecimal(?string $value): bool
    {
        return $value !== null && preg_match('/^\d+(?:\.\d{1,4})?$/', $value) === 1;
    }

    private function equal(?string $left, ?string $right): bool
    {
        return $this->validDecimal($left) && $this->validDecimal($right) && bccomp($left, $right, 4) === 0;
    }

    private function math(?string $a, ?string $b, string $operation): ?string
    {
        if (! $this->validDecimal($a) || ! $this->validDecimal($b)) {
            return null;
        }

        return match ($operation) {
            'mul' => bcmul($a, $b, 4), 'sub' => bcsub($a, $b, 4), 'div' => bcdiv($a, $b, 4), default => bcadd($a, $b, 4)
        };
    }

    private function date(mixed $value): ?string
    {
        try {
            $date = is_numeric($value) ? CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($value)) : CarbonImmutable::parse((string) $value);

            return $date->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function documentType(mixed $value): ?string
    {
        return match (Str::lower($this->text($value) ?? '')) {
            'electronic_ticket', 'tiquete', 'ticket' => Sale::DOCUMENT_ELECTRONIC_TICKET, 'electronic_invoice', 'factura', 'invoice' => Sale::DOCUMENT_ELECTRONIC_INVOICE, default => null
        };
    }

    private function saleCondition(mixed $value): ?string
    {
        return match (Str::lower($this->text($value) ?? '')) {
            'cash', 'contado' => Sale::CONDITION_CASH, 'credit', 'credito', 'crédito' => Sale::CONDITION_CREDIT, default => null
        };
    }
}
