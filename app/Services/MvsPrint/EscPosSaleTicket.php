<?php

namespace App\Services\MvsPrint;

use App\Models\Sale;
use App\Services\Sales\SaleReceiptService;

/**
 * Construye el payload ESC/POS del ticket de una venta ya completada.
 *
 * Reutiliza los datos de la Sale persistida y sus relaciones.
 * NO realiza cálculos financieros nuevos: los montos provienen directamente
 * de Sale, SaleItem y SalePayment.
 *
 * El backend solo prepara comandos de alto nivel (líneas, corte y cajón);
 * el navegador (qz.js) los convierte en bytes ESC/POS y los envía a QZ Tray.
 */
class EscPosSaleTicket
{
    private const ALIGN_CENTER = 'center';

    private const ALIGN_LEFT = 'left';

    private const ALIGN_RIGHT = 'right';

    public function __construct(
        private readonly SaleReceiptService $receiptService,
    ) {}

    /**
     * Construye el descriptor del ticket de venta para la impresora ESC/POS.
     *
     * @param  Sale  $sale  Venta ya completada (load con relaciones)
     * @param  string  $paperWidth  '58' o '80'
     * @param  bool  $autoCut  Cortar después de imprimir
     * @param  bool  $openDrawer  Abrir cajón después de imprimir
     * @param  array|null  $drawerCommand  Comando ESC/POS de apertura
     */
    public function build(
        Sale $sale,
        string $paperWidth = '80',
        bool $autoCut = true,
        bool $openDrawer = false,
        ?array $drawerCommand = null,
    ): array {
        $lines = [];
        $lines = array_merge($lines, $this->headerLines($sale, $paperWidth));
        $lines = array_merge($lines, $this->detailLines($sale, $paperWidth));
        $lines = array_merge($lines, $this->totalsLines($sale, $paperWidth));
        $lines = array_merge($lines, $this->paymentLines($sale, $paperWidth));
        $lines = array_merge($lines, $this->loyaltyLines($sale));
        $lines[] = ['type' => 'empty'];
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => 'Gracias por su compra'];
        $lines[] = ['type' => 'empty'];

        return [
            'lines' => $lines,
            'paper_width' => $paperWidth,
            'auto_cut' => $autoCut,
            'open_drawer' => $openDrawer,
            'drawer_command' => $drawerCommand ?? EscPosTestTicket::defaultDrawerCommand(),
        ];
    }

    private function headerLines(Sale $sale, string $paperWidth): array
    {
        $company = $sale->company;
        $branch = $sale->branch;

        $lines = [];
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $company->trade_name ?? $company->legal_name ?? 'MVS', 'emphasized' => true, 'size' => 'double'];

        if ($company->identification_number) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $company->identification_number];
        }
        if ($branch?->name) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $branch->name];
        }
        if ($branch?->phone) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => 'Tel: '.$branch->phone];
        }

        $lines[] = ['type' => 'empty'];
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $this->documentLabel($sale->document_type), 'emphasized' => true];
        $lines[] = ['type' => 'empty'];

        $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Venta: '.$sale->sale_number];
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Fecha: '.$sale->completed_at?->format('d/m/Y H:i:s') ?? $sale->created_at->format('d/m/Y H:i:s')];

        if ($sale->customer) {
            $customerName = $sale->customer->taxpayer_name ?: $sale->customer->name;
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Cliente: '.$customerName];
            if ($sale->customer->identification) {
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Cedula: '.$sale->customer->identification];
            }
        }

        $lines[] = ['type' => 'empty'];
        $lines[] = ['type' => 'separator'];

        return $lines;
    }

    private function detailLines(Sale $sale, string $paperWidth): array
    {
        $lines = [];
        $is58 = $paperWidth === '58';

        foreach ($sale->items as $item) {
            $productName = $item->product?->name ?? $item->description;

            if ($is58) {
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => $productName, 'emphasized' => true];
                $qty = number_format((float) $item->quantity, 2, ',', '.');
                $price = number_format((float) $item->unit_price, 2, ',', '.');
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => '  '.$qty.' x '.$price];
                if ((float) $item->discount_total > 0) {
                    $disc = number_format((float) $item->discount_total, 2, ',', '.');
                    $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => '  Descuento: -'.$disc];
                }
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_RIGHT, 'value' => number_format((float) $item->total, 2, ',', '.')];
            } else {
                $qty = number_format((float) $item->quantity, 2, ',', '.');
                $price = number_format((float) $item->unit_price, 2, ',', '.');
                $total = number_format((float) $item->total, 2, ',', '.');
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => sprintf('%-30s %7s x %10s  %12s', mb_substr($productName, 0, 30), $qty, $price, $total)];
                if ((float) $item->discount_total > 0) {
                    $disc = number_format((float) $item->discount_total, 2, ',', '.');
                    $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => '  Descuento: -'.$disc];
                }
            }
        }

        $lines[] = ['type' => 'separator'];

        return $lines;
    }

    private function totalsLines(Sale $sale, string $paperWidth): array
    {
        $lines = [];
        $align = $paperWidth === '58' ? self::ALIGN_LEFT : self::ALIGN_RIGHT;

        $lines[] = ['type' => 'text', 'align' => $align, 'value' => 'Subtotal:  '.number_format((float) $sale->subtotal, 2, ',', '.')];

        if ((float) $sale->discount_total > 0) {
            $lines[] = ['type' => 'text', 'align' => $align, 'value' => 'Descuento: -'.number_format((float) $sale->discount_total, 2, ',', '.')];
        }

        if ((float) $sale->tax_total > 0) {
            $lines[] = ['type' => 'text', 'align' => $align, 'value' => 'Impuestos: '.number_format((float) $sale->tax_total, 2, ',', '.')];
        }

        if ((float) $sale->rounding_total !== 0.0) {
            $lines[] = ['type' => 'text', 'align' => $align, 'value' => 'Redondeo: '.number_format((float) $sale->rounding_total, 2, ',', '.')];
        }

        $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => 'TOTAL: '.number_format((float) $sale->total, 2, ',', '.'), 'emphasized' => true];
        $lines[] = ['type' => 'separator'];

        return $lines;
    }

    private function paymentLines(Sale $sale, string $paperWidth): array
    {
        $lines = [];
        $align = $paperWidth === '58' ? self::ALIGN_LEFT : self::ALIGN_RIGHT;

        foreach ($sale->payments as $payment) {
            $methodName = $payment->paymentMethod->name ?? 'Pago';
            $amount = number_format((float) $payment->amount, 2, ',', '.');
            $line = $methodName.': '.$amount;

            if ($payment->reference) {
                $line .= ' (Ref: '.$payment->reference.')';
            }

            $lines[] = ['type' => 'text', 'align' => $align, 'value' => $line];

            if ((float) $payment->received_amount > 0 && (float) $payment->change_amount > 0) {
                $lines[] = ['type' => 'text', 'align' => $align, 'value' => '  Recibido: '.number_format((float) $payment->received_amount, 2, ',', '.')];
                $lines[] = ['type' => 'text', 'align' => $align, 'value' => '  Vuelto:   '.number_format((float) $payment->change_amount, 2, ',', '.')];
            }
        }

        $lines[] = ['type' => 'separator'];

        return $lines;
    }

    private function loyaltyLines(Sale $sale): array
    {
        $summary = $this->receiptService->loyaltySummary($sale);

        if ($summary === null) {
            return [];
        }

        $lines = [];
        $lines[] = ['type' => 'separator'];

        if ($summary['kind'] === 'invitation') {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => '--- Fidelizacion ---', 'emphasized' => true];
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => 'Unete a nuestro programa de fidelidad'];
            if ($summary['portal_name'] ?? null) {
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $summary['portal_name']];
            }
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => 'Escanea para registrarte'];

            return $lines;
        }

        $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => '--- Fidelizacion ---', 'emphasized' => true];

        if (isset($summary['balance_before']) && $summary['kind'] === 'history') {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Saldo anterior: '.number_format((float) $summary['balance_before'], 2, ',', '.')];
        }
        if ((float) ($summary['earned'] ?? 0) > 0) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Puntos ganados: +'.number_format((float) $summary['earned'], 2, ',', '.')];
        }
        if ((float) ($summary['redeemed'] ?? 0) > 0) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Puntos canjeados: -'.number_format((float) $summary['redeemed'], 2, ',', '.')];
        }

        $label = $summary['kind'] === 'history' ? 'Saldo final' : 'Saldo actual';
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => $label.': '.number_format((float) $summary['balance_after'], 2, ',', '.'), 'emphasized' => true];

        return $lines;
    }

    private function documentLabel(string $documentType): string
    {
        return match ($documentType) {
            'electronic_invoice' => 'FACTURA ELECTRONICA',
            'electronic_ticket' => 'TICKET ELECTRONICO',
            default => 'COMPROBANTE',
        };
    }
}
