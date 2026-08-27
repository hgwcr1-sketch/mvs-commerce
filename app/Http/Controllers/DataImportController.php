<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Services\Imports\CustomerImportService;
use App\Services\Imports\InventoryImportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DataImportController extends Controller
{
    public function customers()
    {
        return view('importaciones.clientes');
    }

    public function customerTemplate()
    {
        return $this->spreadsheetDownload(
            CustomerImportService::HEADERS,
            'plantilla_importacion_clientes.xlsx',
            true,
            'Clientes',
        );
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
            fn () => $writer->save('php://output'),
            $fileName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
