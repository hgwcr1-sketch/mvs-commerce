<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\Company;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\CashSession;
use App\Models\CashRegister;
use App\Models\CompanyFiscalConfig;
use App\Models\ElectronicDocument;
use App\Models\User;
use App\Models\CreditNoteApplication;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * DTO inmutable que contiene todos los datos normalizados para renderizar un comprobante.
 *
 * Tanto el HTML (pos/receipt.blade.php) como ESC/POS (EscPosSaleTicket) consumen
 * esta estructura para garantizar paridad funcional.
 */
readonly class SaleReceiptData
{
    /**
     * @param  array{
     *     trade_name: string,
     *     legal_name: string,
     *     identification_number: string|null,
     *     address: string|null,
     *     timezone: string,
     * }  $company
     */
    public function __construct(
        /** @var array<string, mixed> */
        public array $company,
        /** @var array{
         *     name: string,
         *     phone: string|null,
         *     address: string|null,
         * } */
        public array $branch,
        /** @var array{
         *     type: string,
         *     sale_number: string,
         *     completed_at: string,
         *     status: string,
         *     is_voided: bool,
         * } */
        public array $document,
        /** @var array{
         *     name: string,
         * }|null */
        public ?array $cashier,
        /** @var array{
         *     name: string,
         *     identification: string|null,
         *     is_final_consumer: bool,
         * }|null */
        public ?array $customer,
        /** @var list<array{
         *     description: string,
         *     product_code: string|null,
         *     cabys: string|null,
         *     quantity: string,
         *     unit_price: string,
         *     discount_total: string,
         *     tax_total: string,
         *     total: string,
         * }> */
        public array $items,
        /** @var array{
         *     subtotal: string,
         *     discount_total: string,
         *     tax_total: string,
         *     rounding_total: string,
         *     total: string,
         * } */
        public array $totals,
        /** @var list<array{
         *     method: string,
         *     amount: string,
         *     reference: string|null,
         *     received_amount: string|null,
         *     change_amount: string|null,
         *     allows_change: bool,
         * }> */
        public array $payments,
        /** @var array{
         *     is_mixed: bool,
         * } */
        public array $payment_summary,
        /** @var list<array{
         *     credit_note_number: string,
         *     amount: string,
         * }> */
        public array $credit_note_applications,
        /** @var array{
         *     kind: 'invitation'|'history'|'balance',
         *     portal_name: string|null,
         *     registration_url: string|null,
         *     show_registration_qr: bool,
         *     earned: string|null,
         *     redeemed: string|null,
         *     balance_before: string|null,
         *     balance_after: string|null,
         *     adjusted: bool,
         * }|null */
        public ?array $loyalty,
        /** @var array{
         *     session_number: string|null,
         *     cash_register_name: string|null,
         * }|null */
        public ?array $cash_session,
        public string $footer_message,
        /**
         * Identidad fiscal del comprobante electrónico (null si la venta no
         * tiene electronic_document con clave firmada).
         *
         * @var array{
         *     clave: string,
         *     consecutivo: string|null,
         *     status: string,
         *     status_label: string,
         *     is_accepted: bool,
         *     environment: string,
         *     branch_code: string|null,
         *     terminal_code: string|null,
         *     condition: string,
         *     qr_image: string|null,
         * }|null
         */
        public ?array $fiscal = null,
    ) {}

    /**
     * Construye el DTO desde una Sale cargada con relaciones.
     */
    public static function fromSale(Sale $sale): self
    {
        $company = $sale->company;
        $branch = $sale->branch;
        $customer = $sale->customer;
        $cashier = $sale->user;

        // Loyalty: reutilizar la lógica existente
        $loyaltySummary = app(\App\Services\Loyalty\LoyaltySaleReceiptService::class)->forSale($sale);

        $loyalty = null;
        if ($loyaltySummary !== null) {
            $loyalty = [
                'kind' => $loyaltySummary['kind'],
                'portal_name' => $loyaltySummary['portal_name'] ?? null,
                'qr_image' => $loyaltySummary['qr_image'] ?? null,
                'registration_url' => $loyaltySummary['invitation_url'] ?? $loyaltySummary['qr_image'] ?? null,
                'show_registration_qr' => $loyaltySummary['kind'] === 'invitation',
                'earned' => $loyaltySummary['earned'] ?? null,
                'redeemed' => $loyaltySummary['redeemed'] ?? null,
                'balance_before' => $loyaltySummary['balance_before'] ?? null,
                'balance_after' => $loyaltySummary['balance_after'] ?? null,
                'adjusted' => $loyaltySummary['adjusted'] ?? false,
            ];
        }

        return new self(
            company: [
                'trade_name' => $company->trade_name ?? 'MVS',
                'legal_name' => $company->legal_name ?? '',
                'identification_number' => $company->identification_number ?? null,
                'address' => $company->address ?? null,
                'timezone' => $company->timezone ?? 'America/Costa_Rica',
                'email' => $company->email ?? null,
                'phone' => $company->phone ?? null,
            ],
            branch: [
                'name' => $branch->name ?? '',
                'phone' => $branch->phone ?? null,
                'address' => $branch->address ?? $company->address ?? null,
            ],
            document: [
                'type' => self::documentLabel($sale->document_type),
                'sale_number' => $sale->sale_number,
                'completed_at' => $sale->completed_at?->timezone($company->timezone)->format('d/m/Y H:i') ?? $sale->created_at->timezone($company->timezone)->format('d/m/Y H:i'),
                'status' => $sale->status,
                'is_voided' => $sale->status === Sale::STATUS_VOIDED,
            ],
            cashier: $cashier ? ['name' => $cashier->name] : null,
            customer: $customer ? [
                'name' => $customer->taxpayer_name ?? $customer->name,
                'identification' => $customer->identification ?? null,
                'email' => $customer->email ?? null,
                'is_final_consumer' => $customer->id === null || $customer->name === 'Consumidor Final',
            ] : [
                'name' => 'Consumidor Final',
                'identification' => null,
                'email' => null,
                'is_final_consumer' => true,
            ],
            items: $sale->items->map(function (SaleItem $item) {
                return [
                    'description' => $item->product?->name ?? $item->description,
                    'product_code' => $item->product_code ?? null,
                    'cabys' => $item->cabys_code ?? null,
                    'tax_rate' => $item->tax_rate ?? null,
                    'quantity' => rtrim(rtrim(number_format((float) $item->quantity, 4, ',', '.'), '0'), ','),
                    'unit_price' => number_format((float) $item->unit_price, 0, ',', '.'),
                    'discount_total' => number_format((float) $item->discount_total, 0, ',', '.'),
                    'tax_total' => number_format((float) $item->tax_total, 0, ',', '.'),
                    'total' => number_format((float) $item->total, 0, ',', '.'),
                ];
            })->toArray(),
            totals: [
                'subtotal' => number_format((float) $sale->subtotal, 0, ',', '.'),
                'discount_total' => number_format((float) $sale->discount_total, 0, ',', '.'),
                'tax_total' => number_format((float) $sale->tax_total, 0, ',', '.'),
                'rounding_total' => number_format((float) $sale->rounding_total, 0, ',', '.'),
                'total' => number_format((float) $sale->total, 0, ',', '.'),
            ],
            payments: $sale->payments->map(function (SalePayment $payment) {
                return [
                    'method' => $payment->paymentMethod->name ?? 'Pago',
                    'amount' => number_format((float) $payment->amount, 0, ',', '.'),
                    'reference' => $payment->reference ?? null,
                    'received_amount' => (float) $payment->received_amount > 0
                        ? number_format((float) $payment->received_amount, 0, ',', '.')
                        : null,
                    'change_amount' => $payment->change_amount !== null
                        ? number_format((float) $payment->change_amount, 0, ',', '.')
                        : null,
                    'allows_change' => (bool) $payment->paymentMethod->allows_change ?? false,
                ];
            })->toArray(),
            payment_summary: [
                'is_mixed' => $sale->payments->count() >= 2,
            ],
            credit_note_applications: $sale->creditNoteApplicationsAsDestination->map(function (CreditNoteApplication $application) {
                return [
                    'credit_note_number' => $application->creditNote->credit_note_number ?? 'NC',
                    'amount' => number_format((float) $application->amount, 0, ',', '.'),
                ];
            })->toArray(),
            loyalty: $loyalty,
            cash_session: $sale->cashSession ? [
                'session_number' => $sale->cashSession->session_number ?? null,
                'cash_register_name' => $sale->cashSession->cashRegister->name ?? null,
            ] : null,
            footer_message: 'Gracias por su compra',
            fiscal: self::fiscalFor($sale),
        );
    }

    /**
     * Identidad fiscal de la venta: intento aceptado preferido, si no el más
     * reciente con clave firmada. Devuelve null cuando no hay documento
     * electrónico con clave (ventas normales / aún no emitidas).
     */
    private static function fiscalFor(Sale $sale): ?array
    {
        $docs = ElectronicDocument::query()
            ->where('company_id', (int) $sale->company_id)
            ->where('sale_id', $sale->id)
            ->orderByDesc('attempt_number')
            ->get();

        if ($docs->isEmpty()) {
            return null;
        }

        $doc = $docs->firstWhere('status', 'accepted')
            ?? $docs->first(fn (ElectronicDocument $candidate) => $candidate->clave !== null);

        if (! $doc || ! $doc->clave) {
            return null;
        }

        $config = CompanyFiscalConfig::query()->where('company_id', $sale->company_id)->first();

        return [
            'clave' => $doc->clave,
            'consecutivo' => $doc->consecutivo,
            'status' => $doc->status,
            'status_label' => self::fiscalStatusLabel($doc->status),
            'is_accepted' => $doc->status === 'accepted',
            'environment' => $doc->environment === 'production' ? 'Producción' : 'Pruebas',
            'branch_code' => $config?->fiscal_branch_code,
            'terminal_code' => $config?->fiscal_terminal_code,
            'economic_activity' => $config?->economic_activity,
            'condition' => $sale->sale_condition === Sale::CONDITION_CREDIT ? 'Crédito' : 'Contado',
            'qr_image' => self::fiscalQr($doc->clave),
        ];
    }

    private static function fiscalStatusLabel(string $status): string
    {
        return match ($status) {
            'accepted' => 'Aceptado',
            'rejected' => 'Rechazado',
            'error' => 'Envío fallido',
            default => 'En proceso',
        };
    }

    /**
     * QR de representación (clave de 50 dígitos en el endpoint público de
     * Hacienda). SVG embebido: funciona en navegador y en DomPDF.
     */
    private static function fiscalQr(string $clave): ?string
    {
        if (! class_exists(QRCode::class)) {
            return null;
        }

        try {
            $svg = (new QRCode(new QROptions([
                'outputInterface' => QRMarkupSVG::class,
                'eccLevel' => EccLevel::H,
                'scale' => 6,
                'svgAddXmlHeader' => false,
                'outputBase64' => false,
            ])))->render('https://api.comprobanteselectronicos.go.cr/recepcion/v1/comprobantes/'.$clave);
        } catch (\Throwable) {
            return null;
        }

        return is_string($svg) ? 'data:image/svg+xml;base64,'.base64_encode($svg) : null;
    }

    private static function documentLabel(string $documentType): string
    {
        return Sale::receiptLabel($documentType);
    }
}
