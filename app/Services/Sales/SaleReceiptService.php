<?php

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\Sale;
use App\Models\User;
use App\Services\Loyalty\LoyaltySaleReceiptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

class SaleReceiptService
{
    public const FORMATS = ['80mm', '58mm', 'letter'];

    public function __construct(private readonly LoyaltySaleReceiptService $loyalty) {}

    public function authorizedSale(Sale $sale, User $user, int $companyId, int $branchId): Sale
    {
        abort_unless((int) $sale->company_id === $companyId, 404);
        abort_unless((int) $sale->branch_id === $branchId, 404);
        $company = Company::query()->findOrFail($companyId);
        abort_unless($company->isModuleEnabled('sales'), 403);
        $isCreator = (int) $sale->user_id === (int) $user->id;
        abort_unless($isCreator || $user->hasPermission('ventas.ver', $company), 403);

        return $sale->load(['branch', 'user', 'customer', 'items', 'payments.paymentMethod', 'cashSession.cashRegister']);
    }

    public function format(Sale $sale, ?string $requested): string
    {
        return in_array($requested, self::FORMATS, true) ? $requested : ($sale->branch->receipt_format ?: '80mm');
    }

    public function pdf(Sale $sale, Company $company, string $format = 'letter'): DomPdf
    {
        $viewData = [
            'sale' => $sale,
            'company' => $company,
            'format' => $format,
            'autoPrint' => false,
            'pdfMode' => true,
            'loyalty' => $this->loyalty->forSale($sale),
        ];

        if ($format === 'letter') {
            $pdf = Pdf::loadView('pos.receipt', $viewData);
            $pdf->setPaper('letter');

            return $pdf;
        }

        // Térmico 58/80mm: altura dinámica eficiente sin riesgo de corte
        // 1 render de medición con papel grande + 1 render final (máx 3 intentos si subestima)
        $html = view('pos.receipt', $viewData)->render();
        $widthPt = $format === '58mm' ? 164.41 : 226.77;
        $tailPt = 28.35; // ~10mm papel + 6mm CSS = ~16mm cola total

        $items = $sale->items->count();
        $payments = $sale->payments->count();
        $hasLoyalty = $this->loyalty->forSale($sale) !== null;

        // Estimación generosa afinada por formato (58mm 2 líneas necesita más alto por item)
        if ($format === '58mm') {
            $estimatePt = 380 + ($items * 48) + ($payments * 26) + ($hasLoyalty ? 85 : 0);
        } else {
            $estimatePt = 320 + ($items * 32) + ($payments * 22) + ($hasLoyalty ? 75 : 0);
        }
        $paperHeightPt = (int) max($estimatePt + $tailPt, 280);
        $paperHeightPt = min($paperHeightPt, 6000);

        // Verificación con page_count: si corta (2+ págs) aumentamos 40% y reintentamos
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $pdf = Pdf::loadHTML($html);
            $pdf->setPaper([0, 0, $widthPt, $paperHeightPt]);
            @$pdf->getDomPDF()->render();
            if ($pdf->getDomPDF()->getCanvas()->get_page_count() <= 1) {
                return $pdf;
            }
            $paperHeightPt = (int) min($paperHeightPt * 1.4, 6000);
            if ($paperHeightPt >= 6000) {
                break;
            }
        }

        return $pdf;
    }

    public function loyaltySummary(Sale $sale): ?array
    {
        return $this->loyalty->forSale($sale);
    }
}
