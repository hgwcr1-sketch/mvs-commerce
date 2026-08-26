<?php

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\Sale;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

class SaleReceiptService
{
    public const FORMATS = ['80mm', '58mm', 'letter'];

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
        $pdf = Pdf::loadView('pos.receipt', [
            'sale' => $sale,
            'company' => $company,
            'format' => $format,
            'autoPrint' => false,
            'pdfMode' => true,
        ]);
        $pdf->setPaper($format === 'letter' ? 'letter' : [0, 0, $format === '58mm' ? 164.41 : 226.77, 841.89]);

        return $pdf;
    }
}
