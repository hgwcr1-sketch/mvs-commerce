<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\InventoryMigrationBatch;
use App\Models\Product;
use App\Services\Inventory\InventoryPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class InventoryMigrationImportService
{
    public const HEADERS = [
        'origen_migracion*', 'clave_fila*', 'tipo_registro*', 'fecha*', 'codigo_sucursal*',
        'codigo_producto', 'codigo_barras', 'tipo_movimiento', 'cantidad*', 'stock_anterior',
        'stock_nuevo', 'stock_minimo', 'stock_maximo', 'referencia', 'notas',
    ];

    private const MAP = [
        'origen_migracion' => 'source_key', 'clave_fila' => 'row_key', 'tipo_registro' => 'record_type',
        'fecha' => 'occurred_at', 'codigo_sucursal' => 'branch_code', 'codigo_producto' => 'product_code',
        'codigo_barras' => 'barcode', 'tipo_movimiento' => 'movement_type', 'cantidad' => 'quantity',
        'stock_anterior' => 'previous_stock', 'stock_nuevo' => 'new_stock', 'stock_minimo' => 'minimum_stock',
        'stock_maximo' => 'maximum_stock', 'referencia' => 'source_reference', 'notas' => 'notes',
    ];

    public function preview(string $path, int $companyId, array $allowedBranchIds): array
    {
        $source = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        if (count($source) < 2) {
            throw ValidationException::withMessages(['migration_file' => 'El archivo debe incluir encabezados y al menos una fila.']);
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
            throw ValidationException::withMessages(['migration_file' => 'El archivo no contiene filas para revisar.']);
        }

        return $this->validate($rows, $companyId, $allowedBranchIds);
    }

    public function confirm(array $preview, int $companyId, int $userId, array $allowedBranchIds): int
    {
        if ((int) ($preview['company_id'] ?? 0) !== $companyId) {
            throw ValidationException::withMessages(['migration_file' => 'La vista previa no pertenece a la empresa activa.']);
        }
        $rows = $this->validate($preview['rows'] ?? [], $companyId, $allowedBranchIds);
        if ($invalid = collect($rows)->firstWhere('valid', false)) {
            $error = $invalid['errors'][0] ?? ['field' => 'fila', 'message' => 'dato inválido'];
            throw ValidationException::withMessages(['migration_file' => "La importación cambió o contiene errores. Fila {$invalid['row_number']}, {$error['field']}: {$error['message']}"]);
        }

        return DB::transaction(function () use ($rows, $companyId, $userId): int {
            $batch = InventoryMigrationBatch::create([
                'company_id' => $companyId, 'user_id' => $userId, 'source_key' => $rows[0]['source_key'],
                'row_count' => count($rows), 'imported_at' => now(),
            ]);
            $posting = app(InventoryPostingService::class);
            foreach ($rows as $row) {
                $branch = Branch::query()->where('company_id', $companyId)->findOrFail($row['branch_id']);
                $product = Product::query()->where('company_id', $companyId)->with('unit')->findOrFail($row['product_id']);
                $notes = trim(($row['source_reference'] ? 'Referencia: '.$row['source_reference'].'. ' : '').($row['notes'] ?? '')) ?: null;
                if ($row['record_type'] === 'initial_balance') {
                    $posting->postInitialMigration($branch, $product, $userId, $batch->id, $row['quantity'], $row['minimum_stock'], $row['maximum_stock'], $row['occurred_at'], $notes);
                } else {
                    $posting->postHistoricalMigration($branch, $product, $userId, $batch->id, $row['movement_type'], $row['quantity'], $row['previous_stock'], $row['new_stock'], $row['occurred_at'], $notes);
                }
            }

            return count($rows);
        });
    }

    private function headers(array $headers): array
    {
        $resolved = [];
        foreach ($headers as $column => $header) {
            $key = trim(Str::of((string) $header)->ascii()->lower()->replace([' ', '-', '*'], ['_', '_', ''])->toString(), '_');
            $resolved[$column] = self::MAP[$key] ?? null;
        }
        foreach (['source_key', 'row_key', 'record_type', 'occurred_at', 'branch_code', 'quantity'] as $required) {
            if (! in_array($required, $resolved, true)) {
                throw ValidationException::withMessages(['migration_file' => 'Falta una columna obligatoria. Descargue la plantilla vigente.']);
            }
        }

        return $resolved;
    }

    private function normalize(array $data, int $rowNumber, int $companyId): array
    {
        $branchCode = $this->text($data['branch_code'] ?? null);
        $productCode = $this->text($data['product_code'] ?? null);
        $barcode = $this->text($data['barcode'] ?? null);
        $branch = Branch::query()->where('company_id', $companyId)->where('code', $branchCode)->where('is_active', true)->first();
        $byCode = $productCode === null ? null : Product::query()->where('company_id', $companyId)->where('internal_code', $productCode)->first();
        $byBarcode = $barcode === null ? null : Product::query()->where('company_id', $companyId)->where(fn ($query) => $query->where('barcode', $barcode)->orWhereHas('barcodes', fn ($q) => $q->where('barcode', $barcode)->where('is_active', true)))->first();
        $product = $byCode ?? $byBarcode;
        $recordType = match (Str::lower($this->text($data['record_type'] ?? null) ?? '')) {
            'initial_balance', 'saldo_inicial', 'inicial' => 'initial_balance',
            'historical_movement', 'movimiento_historico', 'histórico', 'historico' => 'historical_movement', default => null,
        };
        $movementType = match (Str::lower($this->text($data['movement_type'] ?? null) ?? '')) {
            'entry', 'entrada' => 'entry', 'exit', 'salida' => 'exit', default => null,
        };

        return [
            'row_number' => $rowNumber, 'source_key' => $this->text($data['source_key'] ?? null),
            'row_key' => $this->text($data['row_key'] ?? null), 'record_type' => $recordType,
            'occurred_at' => $this->date($data['occurred_at'] ?? null), 'branch_code' => $branchCode, 'branch_id' => $branch?->id,
            'product_code' => $productCode, 'barcode' => $barcode, 'product_id' => $product?->id,
            'product_name' => $product?->name, 'product_conflict' => $byCode && $byBarcode && $byCode->id !== $byBarcode->id,
            'tracks_inventory' => $product?->track_inventory, 'allows_decimals' => $product?->unit?->allows_decimals,
            'movement_type' => $movementType, 'quantity' => $this->decimal($data['quantity'] ?? null),
            'previous_stock' => $this->decimal($data['previous_stock'] ?? null), 'new_stock' => $this->decimal($data['new_stock'] ?? null),
            'minimum_stock' => $this->decimal($data['minimum_stock'] ?? null), 'maximum_stock' => $this->decimal($data['maximum_stock'] ?? null),
            'source_reference' => $this->text($data['source_reference'] ?? null), 'notes' => $this->text($data['notes'] ?? null),
            'current_stock' => $branch && $product ? $this->stock($branch->id, $product->id) : null,
            'valid' => true, 'errors' => [],
        ];
    }

    private function validate(array $rows, int $companyId, array $allowedBranchIds): array
    {
        $sourceKeys = collect($rows)->pluck('source_key')->filter()->unique();
        $batchExists = $sourceKeys->count() === 1 && InventoryMigrationBatch::query()->where('company_id', $companyId)->where('source_key', $sourceKeys->first())->exists();
        $seenRows = [];
        $seenInitial = [];
        $chains = [];
        foreach ($rows as $index => $row) {
            $errors = [];
            if ($sourceKeys->count() !== 1 || ! $row['source_key']) {
                $errors[] = ['field' => 'origen_migracion', 'message' => 'Todas las filas deben compartir una única clave de origen.'];
            }
            if ($batchExists) {
                $errors[] = ['field' => 'origen_migracion', 'message' => 'Este origen ya fue importado; el reintento no duplicará movimientos.'];
            }
            if (! $row['row_key'] || isset($seenRows[$row['row_key']])) {
                $errors[] = ['field' => 'clave_fila', 'message' => 'Debe ser única dentro del archivo.'];
            }
            $seenRows[$row['row_key']] = true;
            if (! $row['record_type']) {
                $errors[] = ['field' => 'tipo_registro', 'message' => 'Use saldo_inicial o movimiento_historico.'];
            }
            if (! $row['occurred_at']) {
                $errors[] = ['field' => 'fecha', 'message' => 'La fecha es inválida.'];
            }
            if (! $row['branch_id'] || ! in_array($row['branch_id'], $allowedBranchIds, true)) {
                $errors[] = ['field' => 'codigo_sucursal', 'message' => 'La sucursal no existe o no está autorizada para este usuario.'];
            }
            if (! $row['product_code'] && ! $row['barcode']) {
                $errors[] = ['field' => 'producto', 'message' => 'Indique código de producto o código de barras.'];
            }
            if (! $row['product_id']) {
                $errors[] = ['field' => 'producto', 'message' => 'El producto no existe en la empresa activa.'];
            }
            if ($row['product_conflict']) {
                $errors[] = ['field' => 'producto', 'message' => 'Código y barcode corresponden a productos distintos.'];
            }
            if ($row['product_id'] && ! $row['tracks_inventory']) {
                $errors[] = ['field' => 'producto', 'message' => 'El producto no controla inventario.'];
            }
            foreach (['quantity' => 'cantidad', 'minimum_stock' => 'stock_minimo', 'maximum_stock' => 'stock_maximo'] as $field => $label) {
                if ($row[$field] !== null && ! $this->validDecimal($row[$field])) {
                    $errors[] = ['field' => $label, 'message' => 'Use un decimal no negativo con máximo cuatro decimales.'];
                }
            }
            if (! $this->validDecimal($row['quantity'])) {
                $errors[] = ['field' => 'cantidad', 'message' => 'Es obligatoria y admite máximo cuatro decimales.'];
            }
            if ($row['allows_decimals'] === false && $this->validDecimal($row['quantity']) && bccomp($row['quantity'], bcadd($row['quantity'], '0', 0), 4) !== 0) {
                $errors[] = ['field' => 'cantidad', 'message' => 'La unidad solo admite cantidades enteras.'];
            }
            if ($this->validDecimal($row['minimum_stock']) && $this->validDecimal($row['maximum_stock']) && bccomp($row['minimum_stock'], $row['maximum_stock'], 4) > 0) {
                $errors[] = ['field' => 'stock_maximo', 'message' => 'No puede ser menor que el mínimo.'];
            }
            $pair = $row['branch_id'].'|'.$row['product_id'];
            if ($row['record_type'] === 'initial_balance') {
                if (isset($seenInitial[$pair])) {
                    $errors[] = ['field' => 'tipo_registro', 'message' => 'El saldo inicial del producto/sucursal está repetido.'];
                }
                $seenInitial[$pair] = true;
                if ($row['movement_type'] || $row['previous_stock'] !== null || $row['new_stock'] !== null) {
                    $errors[] = ['field' => 'tipo_registro', 'message' => 'Saldo inicial no usa tipo de movimiento ni stocks anterior/nuevo.'];
                }
                if ($row['branch_id'] && $row['product_id'] && bccomp($this->stock($row['branch_id'], $row['product_id']), $row['current_stock'] ?? '0', 4) !== 0) {
                    $errors[] = ['field' => 'cantidad', 'message' => 'El stock actual cambió después del preview; vuelva a cargar el archivo.'];
                }
            }
            if ($row['record_type'] === 'historical_movement') {
                if (! $row['movement_type']) {
                    $errors[] = ['field' => 'tipo_movimiento', 'message' => 'Use entrada o salida.'];
                }
                if ($this->validDecimal($row['quantity']) && bccomp($row['quantity'], '0', 4) <= 0) {
                    $errors[] = ['field' => 'cantidad', 'message' => 'El movimiento histórico debe ser mayor que cero.'];
                }
                if (! $this->validDecimal($row['previous_stock']) || ! $this->validDecimal($row['new_stock'])) {
                    $errors[] = ['field' => 'stock_anterior', 'message' => 'Los stocks anterior y nuevo son obligatorios con cuatro decimales máximo.'];
                }
                if ($this->validDecimal($row['previous_stock']) && $this->validDecimal($row['new_stock']) && $this->validDecimal($row['quantity']) && $row['movement_type']) {
                    $expected = $row['movement_type'] === 'entry' ? bcadd($row['previous_stock'], $row['quantity'], 4) : bcsub($row['previous_stock'], $row['quantity'], 4);
                    if (bccomp($expected, $row['new_stock'], 4) !== 0 || bccomp($expected, '0', 4) < 0) {
                        $errors[] = ['field' => 'stock_nuevo', 'message' => "No concilia; el valor esperado es {$expected}."];
                    }
                    if (isset($chains[$pair]) && bccomp($chains[$pair], $row['previous_stock'], 4) !== 0) {
                        $errors[] = ['field' => 'stock_anterior', 'message' => 'No continúa el stock nuevo del movimiento histórico anterior.'];
                    }
                    $chains[$pair] = $row['new_stock'];
                }
            }
            $row['errors'] = collect($errors)->unique(fn ($error) => $error['field'].'|'.$error['message'])->values()->all();
            $row['valid'] = $row['errors'] === [];
            $rows[$index] = $row;
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

    private function stock(int $branchId, int $productId): string
    {
        return bcadd((string) (DB::table('branch_product')->where('branch_id', $branchId)->where('product_id', $productId)->value('stock') ?? 0), '0', 4);
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
}
