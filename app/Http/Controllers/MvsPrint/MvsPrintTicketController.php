<?php

namespace App\Http\Controllers\MvsPrint;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\MvsPrint\EscPosSaleTicket;
use App\Services\MvsPrint\MvsPrintTerminalResolver;
use App\Services\Sales\SaleReceiptService;
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
        MvsPrintTerminalResolver $resolver,
        SaleReceiptService $receipts,
    ): JsonResponse {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        abort_unless(
            $sale->company_id === $companyId && $sale->branch_id === $branchId,
            404,
        );

        abort_unless($sale->status === Sale::STATUS_COMPLETED, 404);

        $terminalUuid = $request->query('terminal_uuid');

        $terminal = $resolver->resolve($companyId, $branchId, $terminalUuid);
        $reprint = $request->boolean('reprint');
        if ($reprint) {
            $receipts->authorizedSale($sale, $request->user(), $companyId, $branchId);
            abort_unless($terminal && filled($terminal->printer_name), 422, 'No hay terminal de impresión disponible.');
        }

        $sale->loadMissing([
            'company',
            'branch',
            'customer',
            'items.product',
            'payments.paymentMethod',
            'cashSession.cashRegister',
            'user',
        ]);

        $paperWidth = $terminal?->paper_width ?? '80';
        $autoCut = $terminal?->auto_cut ?? true;
        $openDrawer = ! $reprint && ($terminal?->open_drawer ?? false);
        $drawerCommand = $terminal?->drawer_command;

        // Usar SaleReceiptData como fuente única
        $receiptData = $receipts->buildReceiptData($sale);

        $payload = $ticketService->build(
            $receiptData,
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
            'qz' => [
                'signature_url' => route('mvs.print.signature'),
                'certificate_url' => route('mvs.print.certificate'),
                'signed_mode' => true,
            ],
        ]);
    }
}
