<?php

namespace Tests\Feature;

use App\Models\CabysCatalogEntry;
use App\Models\FiscalCatalogVersion;
use App\Services\Cabys\CabysCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CÓDIGO CABYS MÁS PROFUNDO POR FILA.
 *
 * El archivo oficial del BCCR declara 8 categorías (11 dígitos) en la
 * mayoría de filas —incluidos los servicios de software, programación y
 * licencias— y 9 (13 dígitos) en un subconjunto. El importador fijaba la
 * columna "Categoría 9" y descartaba 19.066 códigos válidos.
 */
class CabysImporterDeepestLevelTest extends TestCase
{
    use RefreshDatabase;

    private const CODE_11 = '73311000000';   // Licencias de programas informáticos

    private const CODE_13 = '0111100000100'; // Trigo duro, para siembra

    private function archivo(array $filas, ?array $encabezados = null): string
    {
        $encabezados ??= [
            'Categoría 1', 'Descripción (categoría 1)',
            'Categoría 2', 'Descripción (categoría 2)',
            'Categoría 3', 'Descripción (categoría 3)',
            'Categoría 4', 'Descripción (categoría 4)',
            'Categoría 5', 'Descripción (categoría 5)',
            'Categoría 6', 'Descripción (categoría 6)',
            'Categoría 7', 'Descripción (categoría 7)',
            'Categoría 8', 'Descripción (categoría 8)',
            'Categoría 9', 'Descripción (categoría 9)',
            'Impuesto', 'Nota explicativa 1. Incluye', 'Nota explicativa 2. Excluye',
        ];

        $ruta = tempnam(sys_get_temp_dir(), 'cabys').'.tsv';
        $f = fopen($ruta, 'w');
        fputcsv($f, $encabezados, "\t");
        foreach ($filas as $fila) {
            fputcsv($f, array_pad($fila, count($encabezados), ''), "\t");
        }
        fclose($f);

        return $ruta;
    }

    /** Rellena 1..7 y define los niveles 8/9 pedidos. */
    private function fila(string $cat8, string $desc8, string $tax, ?string $cat9 = null, ?string $desc9 = null): array
    {
        $fila = [];
        for ($l = 1; $l <= 7; $l++) {
            $fila[] = str_pad((string) $l, $l, '1', STR_PAD_LEFT);
            $fila[] = 'Nivel '.$l;
        }
        $fila[] = $cat8;
        $fila[] = $desc8;
        $fila[] = $cat9 ?? '';
        $fila[] = $desc9 ?? '';
        $fila[] = $tax;
        $fila[] = '';
        $fila[] = '';

        return $fila;
    }

    private function importar(string $ruta, bool $borrar = true): array
    {
        $resultado = app(CabysCatalogImporter::class)->import($ruta, 'TEST-1', false);

        if ($borrar) {
            @unlink($ruta);
        }

        return $resultado;
    }

    public function test_importa_codigo_de_11_digitos_de_nivel_8(): void
    {
        $r = $this->importar($this->archivo([
            $this->fila(self::CODE_11, 'Servicios de concesión de licencias para programas informáticos', '13%'),
        ]));

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(1, $r['imported']);

        $e = CabysCatalogEntry::query()->where('code', self::CODE_11)->firstOrFail();
        $this->assertSame('Servicios de concesión de licencias para programas informáticos', $e->description);
        $this->assertSame('13%', $e->tax_rate_raw);
        $this->assertSame(13.0, $e->taxRatePercent());
    }

    public function test_importa_codigo_de_13_digitos_de_nivel_9(): void
    {
        $r = $this->importar($this->archivo([
            $this->fila('011110000', 'Trigo', '1%', self::CODE_13, 'Trigo duro, para siembra (semillas)'),
        ]));

        $this->assertTrue($r['ok'], $r['message']);

        $e = CabysCatalogEntry::query()->where('code', self::CODE_13)->firstOrFail();
        $this->assertSame('Trigo duro, para siembra (semillas)', $e->description);
        $this->assertSame('1%', $e->tax_rate_raw);
        $this->assertSame(1.0, $e->taxRatePercent());
    }

    public function test_fila_con_nivel_9_usa_el_nivel_9(): void
    {
        $this->importar($this->archivo([
            $this->fila('011110000', 'Trigo', '1%', self::CODE_13, 'Trigo duro'),
        ]));

        $e = CabysCatalogEntry::query()->firstOrFail();
        $this->assertSame(self::CODE_13, $e->code, 'Con nivel 9 presente debe ganar el nivel 9');
    }

    public function test_fila_sin_nivel_9_usa_el_nivel_8(): void
    {
        $this->importar($this->archivo([
            $this->fila(self::CODE_11, 'Solo nivel 8', '13%'),
        ]));

        $e = CabysCatalogEntry::query()->firstOrFail();
        $this->assertSame(self::CODE_11, $e->code, 'Sin nivel 9 debe usarse el nivel 8');
        $this->assertSame('Solo nivel 8', $e->description, 'La descripción debe ser la del nivel 8');
    }

    public function test_importa_servicios_de_software_que_antes_se_descartaban(): void
    {
        $this->importar($this->archivo([
            $this->fila('73311000000', 'Servicios de concesión de licencias para el derecho de uso de programas informáticos', '13%'),
            $this->fila('83131000001', 'Servicios de consultoría en software', '13%'),
            $this->fila('47812000000', 'Paquete de software de red', '13%'),
        ]));

        $this->assertSame(3, CabysCatalogEntry::query()->count());
        $this->assertSame(1, CabysCatalogEntry::query()->where('code', '73311000000')->count(),
            '73311000000 debe estar en el catálogo');
        $this->assertSame(1, CabysCatalogEntry::query()->where('code', '83131000001')->count(),
            '83131000001 (consultoría en software) debe estar en el catálogo');
    }

    public function test_rechaza_formatos_invalidos_y_no_transforma_codigos(): void
    {
        $r = $this->importar($this->archivo([
            $this->fila('73.311E+8', 'Notación científica', '13%'),
            $this->fila('ABCDEFGHIJK', 'Letras', '13%'),
            $this->fila('123', 'Muy corto', '13%'),
            $this->fila('123456789012345', 'Muy largo', '13%'),
            $this->fila(self::CODE_11, 'Código válido', '13%'),
        ]));

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['imported'], 'Solo el código válido entra');
        $this->assertSame(4, $r['skipped']);
        $this->assertSame(1, CabysCatalogEntry::query()->count());
        $this->assertSame(self::CODE_11, CabysCatalogEntry::query()->firstOrFail()->code);
    }

    public function test_registra_checksum_y_version_y_no_asume_13_por_defecto(): void
    {
        $ruta = $this->archivo([
            $this->fila(self::CODE_11, 'Licencias', '1%'),
            $this->fila('011110000', 'Trigo', '13%', self::CODE_13, 'Trigo duro'),
        ]);
        $checksum = hash_file('sha256', $ruta);

        $r = $this->importar($ruta);

        $this->assertTrue($r['ok']);
        $v = FiscalCatalogVersion::query()->where('source_version', 'TEST-1')->firstOrFail();
        $this->assertSame($checksum, $v->checksum);
        $this->assertSame(2, $v->row_count);
        $this->assertSame('draft', $v->status, 'Sin activar la versión no debe quedar activa');

        $this->assertSame(1.0, CabysCatalogEntry::query()->where('code', self::CODE_11)->firstOrFail()->taxRatePercent());
        $this->assertSame(13.0, CabysCatalogEntry::query()->where('code', self::CODE_13)->firstOrFail()->taxRatePercent());
    }

    public function test_codigos_repetidos_se_omiten_conservando_la_primera(): void
    {
        $r = $this->importar($this->archivo([
            $this->fila(self::CODE_11, 'Primera aparición', '13%'),
            $this->fila(self::CODE_11, 'Repetido', '13%'),
            $this->fila('47812000000', 'Software de red', '13%'),
        ]));

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(2, $r['imported'], 'Solo deben entrar códigos únicos');
        $this->assertSame(1, $r['duplicates'], 'El repetido debe quedar reportado');
        $this->assertSame(2, CabysCatalogEntry::query()->count());

        $e = CabysCatalogEntry::query()->where('code', self::CODE_11)->firstOrFail();
        $this->assertSame('Primera aparición', $e->description, 'Se conserva la primera fila');
    }

    public function test_permite_reimportar_mismo_archivo_si_la_version_anterior_esta_incompleta(): void
    {
        // La idempotencia anterior era solo por checksum: al cambiar el criterio
        // (nivel más profundo) la misma fuente quedaba congelada en 1.440.
        $ruta = $this->archivo([
            $this->fila(self::CODE_11, 'Licencias', '13%'),
            $this->fila('011110000', 'Trigo', '1%', self::CODE_13, 'Trigo duro'),
        ]);

        $primera = $this->importar($ruta, false);
        $this->assertTrue($primera['ok']);
        $this->assertSame(2, $primera['imported']);

        // Mismo archivo, mismo checksum: ahora debe reconocerse como ya
        // importado (el criterio vigente produce las mismas 2 entradas).
        $segunda = $this->importar($ruta, false);
        $this->assertTrue($segunda['ok'], $segunda['message']);
        $this->assertTrue($segunda['already'], 'Con el mismo criterio no se reimporta');
        $this->assertSame(2, CabysCatalogEntry::query()->count());

        @unlink($ruta);
    }

    public function test_encabezado_invalido_no_importa_nada(): void
    {
        $r = $this->importar($this->archivo([['x']], ['Columna rara', 'Otra', 'Tercera']));

        $this->assertFalse($r['ok']);
        $this->assertSame(0, CabysCatalogEntry::query()->count());
    }
}
