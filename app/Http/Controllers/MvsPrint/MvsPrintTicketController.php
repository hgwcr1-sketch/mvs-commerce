<?php

namespace App\Http\Controllers\MvsPrint;

use App\Http\Controllers\Controller;
use App\Models\MvsPrint\MvsPrintTerminal;
use App\Models\Sale;
use App\Services\MvsPrint\EscPosSaleTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint read-only para obtener el payload ESC/POS de una venta ya completada.
 *
 * Protegido por la misma sesión web que el POS (autenticación + empresa/sucursal activa).
 * Solo sirve ventas de la empresa/sucursal activa que estén en estado completada.
 * NO modifica la venta, inventario, pagos, caja ni fidelización.
 */
class MvsPrintTicketController extends Controller
{
    /**
     * Devuelve el payload ESC/POS del ticket de una venta existente.
     *
     * GET /mvs/print/ticket/{sale}
     *
     * El frontend consulta esto después de recibir la respuesta de checkout
     * para intentar impresión automática vía QZ Tray.
     */
    public function __invoke(
        Request $request,
        Sale $sale,
        EscPosSaleTicket $ticketService,
    ): JsonResponse {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        abort_unless(
            $sale->company_id === $companyId && $sale->branch_id === $branchId,
            404,
        );

        abort_unless($sale->status === Sale::STATUS_COMPLETED, 404);

        $terminalUuid = $request->query('terminal_uuid');

        $terminal = null;
        if ($terminalUuid) {
            $terminal = MvsPrintTerminal::query()
                ->forCompany($companyId)
                ->forBranch($branchId)
                ->where('terminal_uuid', $terminalUuid)
                ->where('enabled', true)
                ->first();
        }

        $sale->loadMissing([
            'company',
            'branch',
            'customer',
            'items.product',
            'payments.paymentMethod',
        ]);

        $paperWidth = $terminal?->paper_width ?? '80';
        $autoCut = $terminal?->auto_cut ?? true;
        $openDrawer = $terminal?->open_drawer ?? false;
        $drawerCommand = $terminal?->drawer_command;

        $payload = $ticketService->build(
            $sale,
            $paperWidth,
            $autoCut,
            $openDrawer,
            $drawerCommand,
        );

        return response()->json([
            'success' => true,
            'sale_id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'printer' => $terminal?->printer_name,
            'payload' => $payload,
        ]);
    }
}
