<?php

namespace App\Http\Controllers\MvsPrint;

use App\Http\Controllers\Controller;
use App\Models\Layaway;
use App\Models\LayawayPayment;
use App\Models\Sale;
use App\Services\MvsPrint\EscPosLayawayTicket;
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

    /**
     * Devuelve el payload ESC/POS del comprobante de un apartado.
     *
     * GET /mvs/print/ticket/layaway/{layaway}
     *
     * Es un documento operativo: imprimir o reimprimir NO crea ventas, abonos,
     * movimientos de inventario ni registros comerciales.
     */
    public function layaway(
        Request $request,
        Layaway $layaway,
        EscPosLayawayTicket $ticketService,
        MvsPrintTerminalResolver $resolver,
    ): JsonResponse {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        abort_unless(
            $layaway->company_id === $companyId && $layaway->branch_id === $branchId,
            404,
        );

        $terminalUuid = $request->query('terminal_uuid');
        $terminal = $resolver->resolve($companyId, $branchId, $terminalUuid);
        $reprint = $request->boolean('reprint');
        if ($reprint) {
            abort_unless($terminal && filled($terminal->printer_name), 422, 'No hay terminal de impresión disponible.');
        }

        $paymentMethod = $layaway->payments()->latest('id')->first()?->paymentMethod;

        $paperWidth = $terminal?->paper_width ?? '80';
        $autoCut = $terminal?->auto_cut ?? true;
        $openDrawer = ! $reprint && ($terminal?->open_drawer ?? false) && ($paymentMethod?->allows_change ?? false);
        $drawerCommand = $terminal?->drawer_command;

        $payload = $ticketService->build(
            $layaway,
            $paperWidth,
            $autoCut,
            $openDrawer,
            $drawerCommand,
        );

        return response()->json([
            'success' => true,
            'layaway_id' => $layaway->id,
            'layaway_number' => $layaway->number,
            'printer' => $terminal?->printer_name,
            'payload' => $payload,
            'qz' => [
                'signature_url' => route('mvs.print.signature'),
                'certificate_url' => route('mvs.print.certificate'),
                'signed_mode' => true,
            ],
        ]);
    }

    /**
     * Devuelve el payload ESC/POS del comprobante de un abono.
     *
     * GET /mvs/print/ticket/layaway/{layaway}/payment/{payment}
     *
     * Documento operativo: imprimir o reimprimir NO crea registros comerciales.
     */
    public function layawayPayment(
        Request $request,
        Layaway $layaway,
        LayawayPayment $payment,
        EscPosLayawayTicket $ticketService,
        MvsPrintTerminalResolver $resolver,
    ): JsonResponse {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        abort_unless(
            $layaway->company_id === $companyId && $layaway->branch_id === $branchId,
            404,
        );
        abort_unless($payment->layaway_id === $layaway->id, 404);

        $terminalUuid = $request->query('terminal_uuid');
        $terminal = $resolver->resolve($companyId, $branchId, $terminalUuid);
        $reprint = $request->boolean('reprint');
        if ($reprint) {
            abort_unless($terminal && filled($terminal->printer_name), 422, 'No hay terminal de impresión disponible.');
        }

        $paperWidth = $terminal?->paper_width ?? '80';
        $autoCut = $terminal?->auto_cut ?? true;
        $openDrawer = ! $reprint && ($terminal?->open_drawer ?? false) && ($payment->paymentMethod?->allows_change ?? false);
        $drawerCommand = $terminal?->drawer_command;

        $payload = $ticketService->buildPayment(
            $layaway,
            $payment,
            $paperWidth,
            $autoCut,
            $openDrawer,
            $drawerCommand,
        );

        return response()->json([
            'success' => true,
            'layaway_id' => $layaway->id,
            'layaway_number' => $layaway->number,
            'payment_id' => $payment->id,
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
