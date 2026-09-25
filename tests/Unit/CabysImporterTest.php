<?php

namespace Tests\Unit;

use App\Models\Cabys;
use App\Services\CabysImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

class CabysImporterTest extends TestCase
{
    use RefreshDatabase;

    private CabysImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = new CabysImporter();
    }

    public function test_tab_delimited_file_is_imported_and_percentages_are_parsed(): void
    {
        $file = $this->csvFile([
            '13%',
            '1%',
            '2%',
            '0.5%',
            'Exento',
        ]);

        $count = $this->importer->import($file);

        $this->assertSame(5, $count);
        $this->assertSame(13.0, (float) Cabys::where('code', '5060101000001')->first()->tax_rate);
        $this->assertSame(1.0, (float) Cabys::where('code', '5060101000002')->first()->tax_rate);
        $this->assertSame(2.0, (float) Cabys::where('code', '5060101000003')->first()->tax_rate);
        $this->assertSame(0.5, (float) Cabys::where('code', '5060101000004')->first()->tax_rate);
        $this->assertSame(0.0, (float) Cabys::where('code', '5060101000005')->first()->tax_rate);
    }

    public function test_empty_tax_is_rejected_and_never_defaults_to_13(): void
    {
        $file = $this->csvFile(['']);

        try {
            $this->importer->import($file);
            $this->fail('Expected exception for impuesto vacío');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('nunca se asume 13', $exception->getMessage());
            $this->assertStringContainsString('5060101000001', $exception->getMessage());
        }

        $this->assertDatabaseCount('cabys', 0);
    }

    public function test_unknown_tax_token_is_rejected_and_never_defaults_to_13(): void
    {
        foreach (['na', 'Desconocido', '13 % approx'] as $token) {
            Cabys::query()->delete();
            $file = $this->csvFile([$token]);

            try {
                $this->importer->import($file);
                $this->fail('Expected exception for impuesto «' . $token . '»');
            } catch (\Exception $exception) {
                $this->assertStringContainsString('nunca se asume 13', $exception->getMessage());
            }

            $this->assertDatabaseCount('cabys', 0);
        }
    }

    public function test_semicolon_delimited_file_still_imports(): void
    {
        $file = $this->tempFile(
            "Categoría 9;Descripción Categoría 9;Impuesto\n".
            "5060101000009;Producto de prueba;4%\n"
        );

        $this->assertSame(1, $this->importer->import($file));
        $this->assertSame(4.0, (float) Cabys::where('code', '5060101000009')->first()->tax_rate);
    }

    public function test_numeric_without_percent_sign_is_accepted(): void
    {
        $file = $this->csvFile(['13']);

        $this->assertSame(1, $this->importer->import($file));
        $this->assertSame(13.0, (float) Cabys::where('code', '5060101000001')->first()->tax_rate);
    }

    private function csvFile(array $impuestos): string
    {
        $lines = ["Categoría 9\tDescripción Categoría 9\tImpuesto"];

        foreach ($impuestos as $index => $impuesto) {
            $code = '506010100000' . ($index + 1);
            $lines[] = $code . "\tProducto de prueba " . ($index + 1) . "\t" . $impuesto;
        }

        return $this->tempFile(implode("\n", $lines) . "\n");
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cabys-test-');
        file_put_contents($path, $contents);

        return $path;
    }
}
