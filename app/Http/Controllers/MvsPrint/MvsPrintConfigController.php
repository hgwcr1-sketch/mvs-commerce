<?php

namespace App\Http\Controllers\MvsPrint;

use App\Http\Controllers\Controller;
use App\Services\MvsPrint\MvsPrintTerminalResolver;
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
     * Sin UUID usa únicamente una terminal inequívoca de la empresa/sucursal.
     * Un UUID inválido o múltiples terminales sin vínculo no se resuelven.
     */
    public function show(Request $request, MvsPrintTerminalResolver $resolver): JsonResponse
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');
        $terminalUuid = $request->query('terminal_uuid');

        $terminal = $resolver->resolve($companyId, $branchId, $terminalUuid);

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
