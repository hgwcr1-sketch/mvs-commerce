<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Services\Imports\CustomerImportService;
use App\Services\Imports\HistoricalSaleImportService;
use App\Services\Imports\InventoryImportService;
use App\Services\Imports\InventoryMigrationImportService;
use App\Services\Imports\LoyaltyMigrationImportService;
use App\Services\Imports\MigrationTemplateService;
use App\Services\Imports\ProductImportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DataImportController extends Controller
{
    public function inventoryMigration(Request $request)
    {
        $companyId = (int) session('active_company_id');
        $branches = $this->allowedBranches($request, $companyId);
        $branchId = $branches->firstWhere('id', (int) session('active_branch_id'))?->id
            ?? $branches->first()?->id;

        return view('importaciones.inventario-migracion', compact('branches', 'branchId'));
    }

    public function inventoryMigrationTemplate(MigrationTemplateService $templates)
    {
        return $this->templateDownload($templates->make('inventory', (int) session('active_company_id')), 'plantilla_migracion_inventario_p36.xlsx');
    }

    public function inventoryMigrationPreview(Request $request, InventoryMigrationImportService $import)
    {
        $data = $request->validate([
            'migration_file' => [
                'required',
                'file',
                'max:10240',
                function (string $attribute, mixed $file, \Closure $fail): void {
                    $extension = strtolower((string) $file->getClientOriginalExtension());
                    if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
                        $fail('El archivo debe tener extensión XLSX, XLS o CSV.');
                    }
                },
            ],
            'legacy_branch_id' => ['nullable', 'integer'],
            'legacy_source_key' => ['nullable', 'string', 'max:100'],
            'legacy_occurred_at' => ['nullable', 'date'],
        ]);
        $companyId = (int) session('active_company_id');
        $allowedBranchIds = $this->allowedBranches($request, $companyId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (! empty($data['legacy_branch_id']) && ! in_array((int) $data['legacy_branch_id'], $allowedBranchIds, true)) {
            abort(403, 'No tiene acceso a la sucursal seleccionada.');
        }
        $rows = $import->preview(
            $request->file('migration_file')->getRealPath(),
            $companyId,
            $allowedBranchIds,
            [
                'branch_id' => isset($data['legacy_branch_id']) ? (int) $data['legacy_branch_id'] : null,
                'source_key' => $data['legacy_source_key'] ?? null,
                'occurred_at' => $data['legacy_occurred_at'] ?? null,
            ],
        );
        session(['inventory_migration_preview' => ['company_id' => $companyId, 'rows' => $rows]]);

        return view('importaciones.inventario-migracion-preview', compact('rows'));
    }

    public function inventoryMigrationImport(Request $request, InventoryMigrationImportService $import)
    {
        $preview = session('inventory_migration_preview');
        if (! $preview) {
            return redirect()->route('importaciones.inventario-migracion')->withErrors(['migration_file' => 'La vista previa expiró. Cargue nuevamente el archivo.']);
        }
        $companyId = (int) session('active_company_id');
        $allowedBranchIds = $this->allowedBranches($request, $companyId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $count = $import->confirm($preview, $companyId, (int) $request->user()->id, $allowedBranchIds);
        session()->forget('inventory_migration_preview');

        return redirect()->route('inventario.index')->with('success', "Se migraron {$count} filas de inventario correctamente.");
    }

    public function loyaltyMigration()
    {
        return view('importaciones.fidelidad-migracion');
    }

    public function loyaltyMigrationTemplate(MigrationTemplateService $templates)
    {
        return $this->templateDownload($templates->make('loyalty', (int) session('active_company_id')), 'plantilla_migracion_fidelidad_p37.xlsx');
    }

    public function loyaltyMigrationPreview(Request $request, LoyaltyMigrationImportService $import)
    {
        $request->validate(['migrar_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);
        $companyId = (int) session('active_company_id');
        $preview = $import->preview($request->file('migrar_file')->getRealPath(), $companyId);
        $resolutionKey = $companyId.':'.$preview['source_key'];
        $preview = $import->reuseManualResolutions(
            $preview,
            $companyId,
            session("loyalty_migration_resolutions.{$resolutionKey}", []),
        );
        session(['loyalty_migration_preview' => $preview]);
        $rows = $preview['rows'];

        return view('importaciones.fidelidad-migracion-preview', compact('rows'));
    }

    public function loyaltyMigrationImport(Request $request, LoyaltyMigrationImportService $import)
    {
        $preview = session('loyalty_migration_preview');
        if (! $preview) {
            return redirect()->route('importaciones.fidelidad-migracion')->withErrors(['migrar_file' => 'La vista previa expiró. Cargue nuevamente el archivo.']);
        }
        $companyId = (int) session('active_company_id');
        $count = $import->confirm($preview, $companyId, (int) $request->user()->id);
        session()->forget('loyalty_migration_preview');

        return redirect()->route('loyalty.dashboard')->with('success', "Se migraron {$count} registros de fidelización correctamente.");
    }

    public function loyaltyMigrationResolve(Request $request, LoyaltyMigrationImportService $import)
    {
        $preview = session('loyalty_migration_preview');
        if (! $preview) {
            return redirect()->route('importaciones.fidelidad-migracion')->withErrors(['migrar_file' => 'La vista previa expiró. Cargue nuevamente el archivo.']);
        }

        $validated = $request->validate([
            'selections' => ['required', 'array'],
            'selections.*' => ['nullable', 'integer', 'min:1'],
        ]);
        $preview = $import->resolveCustomers($preview, (int) session('active_company_id'), $validated['selections']);
        $resolutionKey = $preview['company_id'].':'.$preview['source_key'];
        session([
            'loyalty_migration_preview' => $preview,
            "loyalty_migration_resolutions.{$resolutionKey}" => $import->reusableManualResolutions($preview),
        ]);

        return view('importaciones.fidelidad-migracion-preview', ['rows' => $preview['rows']]);
    }

    public function loyaltyMigrationErrors()
    {
        $preview = session('loyalty_migration_preview');
        abort_unless((int) ($preview['company_id'] ?? 0) === (int) session('active_company_id'), 404);
        $rows = collect($preview['rows'] ?? [])->where('valid', false);
        abort_if($rows->isEmpty(), 404);

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['fila', 'campo', 'error']);
            foreach ($rows as $row) {
                foreach ($row['errors'] as $error) {
                    fputcsv($stream, [$row['row_number'], $error['field'], $error['message']]);
                }
            }
            fclose($stream);
        }, 'errores-migracion-fidelidad.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function historicalSales()
    {
        return view('importaciones.ventas-historicas');
    }

    public function historicalSaleTemplate(MigrationTemplateService $templates)
    {
        return $this->templateDownload($templates->make('sales', (int) session('active_company_id')), 'plantilla_ventas_historicas.xlsx');
    }

    public function historicalSalePreview(Request $request, HistoricalSaleImportService $import)
    {
        $request->validate(['sales_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);
        $companyId = (int) session('active_company_id');
        $rows = $import->preview($request->file('sales_file')->getRealPath(), $companyId);
        session(['historical_sale_import_preview' => ['company_id' => $companyId, 'rows' => $rows]]);

        return view('importaciones.ventas-historicas-preview', compact('rows'));
    }

    public function historicalSaleImport(Request $request, HistoricalSaleImportService $import)
    {
        $preview = session('historical_sale_import_preview');
        if (! $preview) {
            return redirect()->route('importaciones.ventas-historicas')->withErrors(['sales_file' => 'La vista previa expiró. Cargue nuevamente el archivo.']);
        }
        $count = $import->confirm($preview, (int) session('active_company_id'), (int) $request->user()->id);
        session()->forget('historical_sale_import_preview');

        return redirect()->route('ventas.index')->with('success', "Se importaron {$count} ventas históricas correctamente.");
    }

    public function products()
    {
        return view('importaciones.productos');
    }

    public function productTemplate(MigrationTemplateService $templates)
    {
        return $this->templateDownload($templates->make('products', (int) session('active_company_id')), 'plantilla_importacion_productos.xlsx');
    }

    public function productPreview(Request $request, ProductImportService $import)
    {
        $request->validate(['product_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);
        $companyId = (int) session('active_company_id');
        $rows = $import->preview($request->file('product_file')->getRealPath(), $companyId);
        session(['product_import_preview' => ['company_id' => $companyId, 'rows' => $rows]]);

        return view('importaciones.productos-preview', compact('rows'));
    }

    public function productImport(ProductImportService $import)
    {
        $preview = session('product_import_preview');
        if (! $preview) {
            return redirect()->route('importaciones.productos')->withErrors([
                'product_file' => 'La vista previa expiró. Cargue nuevamente el archivo.',
            ]);
        }
        $count = $import->confirm($preview, (int) session('active_company_id'));
        session()->forget('product_import_preview');

        return redirect()->route('productos.index')->with('success', "Se importaron {$count} productos correctamente.");
    }

    public function customers()
    {
        return view('importaciones.clientes');
    }

    public function customerTemplate(MigrationTemplateService $templates)
    {
        return $this->templateDownload($templates->make('customers', (int) session('active_company_id')), 'plantilla_importacion_clientes.xlsx');
    }

    public function customerPreview(Request $request, CustomerImportService $import)
    {
        $data = $request->validate([
            'customer_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);
        $companyId = (int) session('active_company_id');
        $rows = $import->preview($request->file('customer_file')->getRealPath(), $companyId);

        session(['customer_import_preview' => ['company_id' => $companyId, 'rows' => $rows]]);

        return view('importaciones.clientes-preview', compact('rows'));
    }

    public function customerImport(Request $request, CustomerImportService $import)
    {
        $preview = session('customer_import_preview');
        if (! $preview) {
            return redirect()->route('importaciones.clientes')->withErrors([
                'customer_file' => 'La vista previa expiró. Cargue nuevamente el archivo.',
            ]);
        }

        $count = $import->confirm($preview, (int) session('active_company_id'));
        session()->forget('customer_import_preview');

        return redirect()->route('clientes.index')->with('success', "Se importaron {$count} clientes correctamente.");
    }

    public function inventory(Request $request)
    {
        $companyId = (int) session('active_company_id');
        $branches = $this->allowedBranches($request, $companyId);
        $branchId = $branches->firstWhere('id', (int) session('active_branch_id'))?->id
            ?? $branches->first()?->id;

        return view('importaciones.inventario', compact('branches', 'branchId'));
    }

    public function inventoryPreview(Request $request, InventoryImportService $import)
    {
        $companyId = (int) session('active_company_id');
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'movement_type' => ['required', 'in:entry,exit'],
            'inventory_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $branch = $this->allowedBranches($request, $companyId)->firstWhere('id', (int) $data['branch_id']);
        abort_unless($branch, 403, 'No tiene acceso a la sucursal seleccionada.');

        $previewRows = $import->preview(
            $request->file('inventory_file')->getRealPath(),
            $companyId,
            $branch,
            $data['movement_type'],
        );

        session(['inventory_import_preview' => [
            'company_id' => $companyId,
            'branch_id' => $branch->id,
            'movement_type' => $data['movement_type'],
            'rows' => $previewRows,
        ]]);

        return view('importaciones.inventario-preview', [
            'previewRows' => $previewRows,
            'branch' => $branch,
            'movementType' => $data['movement_type'],
            'rows' => $previewRows,
            'canConfirm' => $request->user()->hasPermission(
                'inventario.ajustar',
                Company::query()->findOrFail($companyId),
            ),
        ]);
    }

    public function inventoryImport(Request $request, InventoryImportService $import)
    {
        $companyId = (int) session('active_company_id');
        $preview = session('inventory_import_preview');

        if (! $preview) {
            return redirect()->route('importaciones.inventario')->withErrors([
                'inventory_file' => 'La vista previa expiró. Cargue nuevamente el archivo.',
            ]);
        }

        $branch = $this->allowedBranches($request, $companyId)
            ->firstWhere('id', (int) ($preview['branch_id'] ?? 0));
        abort_unless($branch, 403, 'No tiene acceso a la sucursal seleccionada.');

        $import->confirm($preview, $companyId, (int) $request->user()->id);
        session()->forget('inventory_import_preview');

        return redirect()->route('inventario.index')->with('success', 'Inventario importado correctamente.');
    }

    public function inventoryTemplate()
    {
        return $this->spreadsheetDownload(
            ['codigo*', 'nombre*', 'cantidad*', 'categoria', 'marca', 'unidad', 'codigo_barras',
                'cabys', 'costo', 'precio_venta', 'precio_mayoreo', 'precio_especial', 'impuesto',
                'minimo', 'maximo', 'descripcion'],
            'plantilla_importacion_inventario.xlsx',
            true,
        );
    }

    public function inventoryExample()
    {
        return $this->spreadsheetDownload([
            'TEST-001', 'Producto ejemplo', 10, 'Categoria', 'Marca', 'Unidad', '750000000',
            '123456789', 1500, 3000, 2500, 2800, 13, 2, 20, 'Producto de ejemplo',
        ], 'ejemplo_importacion_inventario.xlsx');
    }

    public function inventoryInstructions()
    {
        return Pdf::loadView('pdf.instrucciones-inventario')
            ->download('instrucciones_importacion_inventario.pdf');
    }

    private function allowedBranches(Request $request, int $companyId)
    {
        $company = Company::query()->findOrFail($companyId);
        $canSeeOthers = $request->user()->hasPermission('inventario.ver_otras_sucursales', $company);

        return Branch::query()->where('company_id', $companyId)->where('is_active', true)
            ->when(! $canSeeOthers, fn ($query) => $query->whereKey((int) session('active_branch_id')))
            ->orderBy('name')->get();
    }

    private function spreadsheetDownload(array $row, string $fileName, bool $isTemplate = false, string $title = 'Inventario')
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        if ($isTemplate) {
            $sheet->setTitle($title);
        }
        $sheet->fromArray([$row], null, 'A1');
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            function () use ($writer, $spreadsheet): void {
                try {
                    $writer->save('php://output');
                } finally {
                    $spreadsheet->disconnectWorksheets();
                }
            },
            $fileName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function templateDownload(Spreadsheet $spreadsheet, string $fileName)
    {
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            function () use ($writer, $spreadsheet): void {
                try {
                    $writer->save('php://output');
                } finally {
                    $spreadsheet->disconnectWorksheets();
                }
            },
            $fileName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
