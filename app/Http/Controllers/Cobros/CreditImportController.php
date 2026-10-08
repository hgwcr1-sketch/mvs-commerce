<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Services\Cobros\InitialPortfolioRecordService;
use App\Services\Imports\CustomerImportChunkReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 12B-2 — importación CSV/Excel de créditos históricos por el PRESTAMISTA.
 * Reutiliza el lector instalado (CustomerImportChunkReader) y el motor de
 * persistencia importCredit()/inspectImportRow(): plantilla, vista previa sin
 * escritura, confirmación explícita y resultados por fila. Aislamiento por la
 * empresa activa de la sesión; sin pagos ni recibos ficticios.
 */
class CreditImportController extends Controller
{
    private const MAX_ROWS = 500;

    /** Encabezado normalizado (sin acentos) => clave del motor importCredit(). */
    private const MAP = [
        'identificacion_tipo' => 'identification_type',
        'identificacion' => 'identification',
        'nombre' => 'name',
        'telefono' => 'phone',
        'monto_original' => 'original_amount',
        'saldo_pendiente' => 'balance',
        'cuota' => 'installment',
        'frecuencia' => 'frequency',
        'fecha_primer_vencimiento' => 'next_charge_date',
        'referencia_externa' => 'external_reference',
        'comportamiento' => 'declared_behavior',
        'notas' => 'historical_note',
        'ruta' => 'route_name',
    ];

    public function index()
    {
        return view('cobros.owner.credit-import', ['company' => $this->activeCompany()]);
    }

    /** Plantilla Xlsx con encabezados, instrucciones y catálogos (sin fórmulas). */
    public function template()
    {
        $sheet = new Spreadsheet;
        $data = $sheet->getActiveSheet();
        $data->setTitle('Creditos historicos');

        $headers = [
            'identificacion_tipo', 'identificacion', 'nombre', 'telefono', 'monto_original',
            'saldo_pendiente', 'cuota', 'frecuencia', 'fecha_primer_vencimiento',
            'referencia_externa', 'comportamiento', 'notas', 'ruta',
        ];
        $data->fromArray([$headers], null, 'A1');
        $data->getStyle('A1:M1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $data->getStyle('A1:M1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E3A5F');
        $data->freezePane('A2');
        $data->fromArray([['cedula', '212345678', 'Cliente Ejemplo', '88888888', '50000', '35000', '3500', 'quincenal', '2026-11-05', 'EXT-0001', 'bueno', 'Saldo heredado', '']], null, 'A2');
        foreach (range('A', 'M') as $letter) {
            $data->getColumnDimension($letter)->setWidth(22);
        }

        $guide = $sheet->createSheet();
        $guide->setTitle('INSTRUCCIONES');
        $guide->fromArray([
            ['Campo', 'Obligatorio', 'Formato / valores'],
            ['identificacion_tipo', 'Sí', 'cedula | dimex | pasaporte'],
            ['identificacion', 'Sí', 'Hasta 20 caracteres; cliente de ESTA empresa'],
            ['nombre', 'Sí', 'Hasta 155 caracteres'],
            ['telefono', 'Sí', 'Hasta 30 caracteres'],
            ['monto_original', 'No', 'Número con hasta 4 decimales; ≥ saldo_pendiente'],
            ['saldo_pendiente', 'Sí', 'Número > 0 con hasta 4 decimales'],
            ['cuota', 'Sí', 'Número con hasta 4 decimales'],
            ['frecuencia', 'Sí', 'diario | semanal | quincenal | mensual'],
            ['fecha_primer_vencimiento', 'Sí', 'AAAA-MM-DD'],
            ['referencia_externa', 'Sí', 'Única por empresa, máx. 64; clave anti-duplicados'],
            ['comportamiento', 'Sí', 'bueno | regular | malo | sin_informacion'],
            ['notas', 'No', 'Texto libre, máx. 500'],
            ['ruta', 'No', 'Nombre exacto de una ruta activa donde el usuario es miembro'],
            ['', '', ''],
            ['Reglas', '', ''],
            ['1', '', 'Reimportar la MISMA referencia con los mismos datos no duplica nada.'],
            ['2', '', 'La MISMA referencia con datos distintos genera conflicto (no sobrescribe).'],
            ['3', '', 'Varios créditos de un mismo cliente: una fila por crédito (CxC independiente).'],
            ['4', '', 'Máximo 500 filas por archivo; formatos CSV o Excel.'],
        ], null, 'A1');
        $guide->getStyle('A1:C1')->getFont()->setBold(true);
        $guide->getColumnDimension('A')->setWidth(28);
        $guide->getColumnDimension('B')->setWidth(14);
        $guide->getColumnDimension('C')->setWidth(80);

        $sheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($sheet) {
            (new Xlsx($sheet))->save('php://output');
            $sheet->disconnectWorksheets();
        }, 'plantilla_creditos_historicos.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Vista previa SIN escritura: clasifica cada fila del archivo. */
    public function preview(Request $request, CustomerImportChunkReader $reader)
    {
        $company = $this->activeCompany();
        $request->validate([
            'credit_file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        $file = $request->file('credit_file');
        $rows = $this->readRows($reader, $file->getRealPath());

        $engine = app(InitialPortfolioRecordService::class);
        $results = [];
        foreach ($rows['rows'] as $index => $raw) {
            $mapped = $this->mapRow($rows['headers'], $raw);
            if ($mapped === null) {
                continue; // Fila completamente vacía.
            }
            $check = $engine->inspectImportRow($company, $mapped);
            $results[] = [
                'line' => $index + 2,
                'data' => $mapped,
                'status' => $check['status'],
                'errors' => $check['errors'],
            ];
        }

        $path = $file->store('credit-imports/'.$company->id, 'local');
        abort_if($path === null, 500, 'No se pudo guardar el archivo de importación.');
        session(['cobros_credit_import' => [
            'company_id' => $company->id,
            'path' => $path,
            'fingerprint' => hash_file('sha256', $file->getRealPath()),
        ]]);

        return view('cobros.owner.credit-import', [
            'company' => $company,
            'preview' => $results,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 120),
            'totals' => $this->totals($results),
        ]);
    }

    /** Confirmación explícita: ejecuta importCredit() por fila (idempotente). */
    public function confirm(Request $request, CustomerImportChunkReader $reader, InitialPortfolioRecordService $import)
    {
        $company = $this->activeCompany();
        $state = session('cobros_credit_import');
        abort_unless(
            is_array($state)
            && (int) ($state['company_id'] ?? 0) === (int) $company->id
            && ! empty($state['path'])
            && Storage::disk('local')->exists($state['path']),
            410,
            'La vista previa expiró. Vuelva a cargar el archivo.'
        );

        $request->validate(['confirm' => ['required', 'accepted']]);

        $rows = $this->readRows($reader, Storage::disk('local')->path($state['path']));
        $results = [];
        foreach ($rows['rows'] as $index => $raw) {
            $mapped = $this->mapRow($rows['headers'], $raw);
            if ($mapped === null) {
                continue;
            }

            try {
                $this->applyRoute($company, $request, $mapped);
                $record = $import->importCredit($company, $request->user(), $mapped);
                $results[] = [
                    'line' => $index + 2,
                    'data' => $mapped,
                    'status' => $record->wasRecentlyCreated ? 'imported' : 'duplicate',
                    'errors' => [],
                ];
            } catch (ValidationException $e) {
                $errors = collect($e->errors())->flatten()->all();
                $hasReference = array_key_exists('external_reference', $e->errors());
                $results[] = [
                    'line' => $index + 2,
                    'data' => $mapped,
                    'status' => $hasReference ? 'conflict' : 'invalid',
                    'errors' => $errors,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'line' => $index + 2,
                    'data' => $mapped,
                    'status' => 'error',
                    'errors' => ['Error inesperado al importar la fila.'],
                ];
            }
        }

        Storage::disk('local')->delete($state['path']);
        session()->forget('cobros_credit_import');

        return view('cobros.owner.credit-import-results', [
            'company' => $company,
            'results' => $results,
            'totals' => $this->totals($results),
        ]);
    }

    /** @return array{headers: array<int, mixed>, rows: array<int, array<int, mixed>>, last_row: int} */
    private function readRows(CustomerImportChunkReader $reader, string $path): array
    {
        try {
            $data = $reader->read($path, 2, self::MAX_ROWS);
        } catch (\Throwable) {
            abort(422, 'No se pudo leer el archivo. Use CSV o Excel (máx. 500 filas).');
        }

        if ((int) $data['last_row'] > self::MAX_ROWS + 1) {
            abort(422, 'El archivo excede las '.self::MAX_ROWS.' filas permitidas.');
        }

        return $data;
    }

    /**
     * Encabezados normalizados (minúsculas/sin acentos) => claves del motor.
     *
     * @param  array<int, mixed>  $headers
     * @param  array<int, mixed>  $raw
     * @return array<string, mixed>|null null si la fila está vacía
     */
    private function mapRow(array $headers, array $raw): ?array
    {
        $mapped = [];
        foreach ($headers as $column => $header) {
            $key = Str::ascii(mb_strtolower(trim((string) $header)));
            $key = str_replace([' ', '-'], '_', $key);
            if (! isset(self::MAP[$key])) {
                continue;
            }
            $value = trim((string) ($raw[$column] ?? ''));
            if ($value !== '') {
                $mapped[self::MAP[$key]] = $value;
            }
        }

        return $mapped === [] ? null : $mapped;
    }

    /**
     * Ruta opcional del archivo: solo rutas activas donde el actor es miembro
     * activo; se fija en la sesión para que activeRouteId() la autorice.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyRoute(Company $company, Request $request, array $data): void
    {
        $name = trim((string) ($data['route_name'] ?? ''));
        if ($name === '') {
            return;
        }

        $route = CobrosRoute::forCompany($company->id)->where('active', true)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($route === null) {
            throw ValidationException::withMessages(['ruta' => 'La ruta indicada no existe o no está activa.']);
        }

        $member = $route->users()->where('users.id', $request->user()->id)
            ->where('cobros_route_user.active', true)->exists();
        if (! $member) {
            throw ValidationException::withMessages(['ruta' => 'La ruta no está autorizada para el usuario.']);
        }

        $request->session()->put('active_cobros_route_id', $route->id);
    }

    /** @param  array<int, array<string, mixed>>  $results */
    private function totals(array $results): array
    {
        $totals = ['ready' => 0, 'imported' => 0, 'duplicate' => 0, 'conflict' => 0, 'invalid' => 0, 'error' => 0];
        foreach ($results as $row) {
            $totals[$row['status']] = ($totals[$row['status']] ?? 0) + 1;
        }

        return $totals;
    }

    private function activeCompany(): Company
    {
        $company = Company::find((int) session('active_company_id', 0));
        abort_if($company === null, 403, 'Seleccione una empresa activa.');

        return $company;
    }
}
