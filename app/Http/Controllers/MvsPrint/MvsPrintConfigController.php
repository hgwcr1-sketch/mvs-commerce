<?php

namespace App\Http\Controllers\MvsPrint;

use App\Http\Controllers\Controller;
use App\Models\MvsPrint\MvsPrintTerminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint read-only para obtener la configuración de impresión de una terminal.
 *
 * El frontend (POS) consulta esto para resolver si auto_print está habilitado
 * y qué parámetros usar para la impresión. Usa terminal_uuid del localStorage.
 */
class MvsPrintConfigController extends Controller
{
    /**
     * Retorna la configuración de la terminal solicitada.
     *
     * GET /mvs/print/config?terminal_uuid=...
     *
     * Si no se provee terminal_uuid o no se encuentra, retorna auto_print=false.
     */
    public function show(Request $request): JsonResponse
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');
        $terminalUuid = $request->query('terminal_uuid');

        if (! $terminalUuid) {
            return response()->json([
                'success' => true,
                'auto_print' => false,
                'terminal' => null,
            ]);
        }

        $terminal = MvsPrintTerminal::query()
            ->forCompany($companyId)
            ->forBranch($branchId)
            ->where('terminal_uuid', $terminalUuid)
            ->where('enabled', true)
            ->first();

        if (! $terminal) {
            return response()->json([
                'success' => true,
                'auto_print' => false,
                'terminal' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'auto_print' => $terminal->auto_print,
            'terminal' => [
                'terminal_uuid' => $terminal->terminal_uuid,
                'name' => $terminal->name,
                'printer_name' => $terminal->printer_name,
                'paper_width' => $terminal->paper_width,
                'auto_cut' => $terminal->auto_cut,
                'open_drawer' => $terminal->open_drawer,
            ],
        ]);
    }
}
