<?php

namespace App\Http\Controllers\Cobros;

use App\Models\CobrosCredit;
use Illuminate\Http\Request;

/**
 * BLOQUE 9C — entrega/comprobante del préstamo a cargo del COBRADOR.
 * Reutiliza todo el flujo 9A.2 (firma, snapshot, PDF, WhatsApp) y solo
 * agrega la propiedad: el crédito debe pertenecer al cobrador autenticado.
 */
class CollectorCreditDeliveryController extends OwnerCreditDeliveryController
{
    protected function findOrFailCredit(int $id): CobrosCredit
    {
        $credit = CobrosCredit::query()->findOrFail($id); // otro tenant = 404

        if ((int) $credit->collector_user_id !== (int) auth()->id()) {
            abort(403, 'Este préstamo no le pertenece.');
        }

        $activeRoute = (int) session('active_cobros_route_id', 0);
        if ($activeRoute !== 0 && (int) $credit->cobros_route_id !== $activeRoute) {
            abort(403, 'Este préstamo pertenece a otra ruta.');
        }

        return $credit;
    }

    // El cobrador entrega en SUS rutas (permission:cobros.cobrar): el botón
    // "FIRMAR Y CONFIRMAR ENTREGA" jamás apunta a owner.* (portal_dueno → 403).
    protected function disburseRoute(CobrosCredit $credit): string
    {
        return route('cobros.collector.creditos.disburse', $credit->id);
    }

    protected function backRoute(CobrosCredit $credit): string
    {
        return route('cobros.collector.creditos.entrega', $credit->id);
    }

    protected function pdfRoute(CobrosCredit $credit): string
    {
        return route('cobros.collector.creditos.comprobante_pdf', $credit->id);
    }

    protected function pdfDownloadRoute(CobrosCredit $credit): string
    {
        return route('cobros.collector.creditos.comprobante_pdf', ['credit' => $credit->id, 'descargar' => 1]);
    }
}
