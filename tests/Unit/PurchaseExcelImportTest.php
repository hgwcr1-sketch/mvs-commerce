<?php

namespace Tests\Unit;

use App\Services\Imports\PurchaseExcelImport;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PurchaseExcelImportTest extends TestCase
{
    public function test_it_rejects_a_file_without_quantity_column(): void
    {
        $this->assertImportError(
            ['Código', 'Producto', 'Proveedor', 'Unidad de medida', 'Costo'],
            ['P-1', 'Producto', 'Proveedor', 'Unidad', 10],
            'El archivo no contiene la columna obligatoria Cantidad.',
        );
    }

    #[DataProvider('invalidRows')]
    public function test_it_rejects_invalid_required_row_values(
        array $row,
        string $expectedMessage,
    ): void {
        $this->assertImportError($this->requiredHeaders(), $row, $expectedMessage);
    }

    public static function invalidRows(): array
    {
        return [
            'empty quantity' => [
                ['P-1', 'Producto', 'Proveedor', 'Unidad', null, 10],
                'Fila 2: falta el campo obligatorio Cantidad.',
            ],
            'zero quantity' => [
                ['P-1', 'Producto', 'Proveedor', 'Unidad', 0, 10],
                'Fila 2: la cantidad debe ser mayor que cero.',
            ],
            'empty cost' => [
                ['P-1', 'Producto', 'Proveedor', 'Unidad', 1, null],
                'Fila 2: falta el campo obligatorio Costo.',
            ],
        ];
    }

    public function test_it_imports_a_valid_row(): void
    {
        $rows = $this->readSpreadsheet(
            $this->requiredHeaders(),
            ['P-1', 'Producto', 'Proveedor', 'Unidad', 2, 10.5],
        );

        $this->assertCount(1, $rows);
        $this->assertSame('P-1', $rows[0]['code']);
        $this->assertSame(2.0, $rows[0]['quantity']);
        $this->assertSame(10.5, $rows[0]['cost']);
        $this->assertSame('excel-2', $rows[0]['_row_key']);
    }

    public function test_it_recognizes_quantity_header_with_required_marker(): void
    {
        $headers = $this->requiredHeaders();
        $headers[4] = 'Cantidad *';

        $rows = $this->readSpreadsheet(
            $headers,
            ['P-1', 'Producto', 'Proveedor', 'Unidad', 3, 10],
        );

        $this->assertSame(3.0, $rows[0]['quantity']);
    }

    private function requiredHeaders(): array
    {
        return ['Código', 'Producto', 'Proveedor', 'Unidad de medida', 'Cantidad', 'Costo'];
    }

    private function assertImportError(array $headers, array $row, string $expected): void
    {
        try {
            $this->readSpreadsheet($headers, $row);
            $this->fail('La importación no produjo el error esperado.');
        } catch (ValidationException $exception) {
            $this->assertSame([$expected], $exception->errors()['file'] ?? []);
        }
    }

    private function readSpreadsheet(array $headers, array $row): array
    {
        $path = tempnam(sys_get_temp_dir(), 'purchase-import-');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([$headers]);

        foreach ($row as $column => $value) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($column + 1).'2', $value);
        }
        (new Xlsx($spreadsheet))->save($path);

        try {
            return app(PurchaseExcelImport::class)->read($path);
        } finally {
            unlink($path);
        }
    }
}
