<?php

namespace App\Services\MvsPrint;

use App\Models\Layaway;
use App\Models\LayawayPayment;

/**
 * Construye el payload ESC/POS de los comprobantes de apartados (MVS Commerce).
 *
 * Reutiliza las convenciones visuales de EscPosSaleTicket:
 * - Moneda como símbolo colón (₡, U+20A1); qz.js lo traduce al byte 0x9B
 *   (CP850/CP437) para que la impresora térmica nunca muestre mojibake.
 * - Word-wrap consciente de espacios en 58mm (32) y 80mm (48) fuente A.
 * - El backend solo prepara comandos de alto nivel; el navegador (qz.js)
 *   los convierte a bytes ESC/POS y los envía por QZ Tray.
 *
 * Los comprobantes de apartado/abono son documentos operativos de cobro:
 * imprimir o reimprimir NUNCA crea ventas, abonos ni registros comerciales.
 */
class EscPosLayawayTicket
{
    private const ALIGN_CENTER = 'center';

    private const ALIGN_LEFT = 'left';

    private const ALIGN_RIGHT = 'right';

    /** Ancho aproximado en caracteres de fuente A para cada papel. */
    private const WIDTH_58 = 32;

    private const WIDTH_80 = 48;

    /**
     * Comprobante de apartado completo (productos reservados + pago inicial).
     *
     * @param  bool  $autoCut  Cortar después de imprimir
     * @param  bool  $openDrawer  Abrir cajón después de imprimir
     * @param  array|null  $drawerCommand  Comando ESC/POS de apertura
     */
    public function build(
        Layaway $layaway,
        string $paperWidth = '80',
        bool $autoCut = true,
        bool $openDrawer = false,
        ?array $drawerCommand = null,
    ): array {
        $layaway->loadMissing(['company', 'branch', 'customer', 'creator', 'items.product', 'payments.paymentMethod']);
        $lines = $this->headerLines($layaway, 'COMPROBANTE DE APARTADO', $paperWidth, $layaway->created_at ?? $layaway->paid_at);
        $lines = array_merge($lines, $this->customerLines($layaway, $paperWidth));
        $lines = array_merge($lines, $this->detailLines($layaway, $paperWidth));
        $lines = array_merge($lines, $this->totalLines($layaway, $paperWidth));
        $lines = array_merge($lines, $this->expiryLine($layaway, $paperWidth));
        $lines[] = ['type' => 'separator'];
        $lines = array_merge($lines, $this->paymentLines($layaway->payments->first(), $layaway, 'Prima', $paperWidth));
        $lines[] = ['type' => 'separator'];

        $this->addText($lines, 'Saldo pendiente: '.self::CURRENCY().' '.$this->formatAmount($layaway->balance_due), $this->align($paperWidth), $this->textWidth($paperWidth), true);

        $lines = array_merge($lines, $this->cashSessionLines($layaway->payments->first(), $paperWidth));
        $lines = array_merge($lines, $this->footerLines($paperWidth));

        return [
            'lines' => $lines,
            'paper_width' => $paperWidth,
            'auto_cut' => $autoCut,
            'open_drawer' => $openDrawer,
            'drawer_command' => $drawerCommand ?? EscPosTestTicket::defaultDrawerCommand(),
        ];
    }

    /**
     * Comprobante de abono (recibo de pago individual).
     *
     * @param  bool  $autoCut  Cortar después de imprimir
     * @param  bool  $openDrawer  Abrir cajón después de imprimir
     * @param  array|null  $drawerCommand  Comando ESC/POS de apertura
     */
    public function buildPayment(
        Layaway $layaway,
        LayawayPayment $payment,
        string $paperWidth = '80',
        bool $autoCut = true,
        bool $openDrawer = false,
        ?array $drawerCommand = null,
    ): array {
        $layaway->loadMissing(['company', 'branch', 'customer']);
        $payment->loadMissing(['paymentMethod', 'user', 'cashSession.cashRegister']);
        $lines = $this->headerLines($layaway, 'COMPROBANTE DE ABONO', $paperWidth, $payment->paid_at);
        $lines = array_merge($lines, $this->customerLines($layaway, $paperWidth));
        $lines[] = ['type' => 'separator'];
        $this->addText($lines, 'Abono', self::ALIGN_CENTER, $this->textWidth($paperWidth, 'double'), true, 'double');
        $this->addText($lines, self::CURRENCY().' '.$this->formatAmount($payment->amount), self::ALIGN_CENTER, $this->textWidth($paperWidth, 'double'), true, 'double');
        $lines[] = ['type' => 'separator'];
        $lines = array_merge($lines, $this->paymentLines($payment, $layaway, 'Abono', $paperWidth));
        $lines[] = ['type' => 'separator'];

        $this->addText($lines, 'Saldo pendiente: '.self::CURRENCY().' '.$this->formatAmount($layaway->balance_due), $this->align($paperWidth), $this->textWidth($paperWidth), true);

        $lines = array_merge($lines, $this->cashSessionLines($payment, $paperWidth));
        $lines = array_merge($lines, $this->footerLines($paperWidth));

        return [
            'lines' => $lines,
            'paper_width' => $paperWidth,
            'auto_cut' => $autoCut,
            'open_drawer' => $openDrawer,
            'drawer_command' => $drawerCommand ?? EscPosTestTicket::defaultDrawerCommand(),
        ];
    }

    private function headerLines(Layaway $layaway, string $title, string $paperWidth, ?\DateTimeInterface $date): array
    {
        $lines = [];
        $width = $this->textWidth($paperWidth);

        if ($layaway->company?->trade_name) {
            $this->addText($lines, $layaway->company->trade_name, self::ALIGN_CENTER, $this->textWidth($paperWidth, 'double'), true, 'double');
        }
        $this->addText($lines, 'MVS Commerce', self::ALIGN_CENTER, $width);

        if ($layaway->company?->legal_name) {
            $this->addText($lines, $layaway->company->legal_name, self::ALIGN_CENTER, $width);
        }
        if ($layaway->company?->identification_number) {
            $this->addText($lines, $layaway->company->identification_number, self::ALIGN_CENTER, $width);
        }
        if ($layaway->company?->address) {
            $this->addText($lines, $layaway->company->address, self::ALIGN_CENTER, $width);
        }

        $lines[] = ['type' => 'empty'];

        if ($layaway->branch?->name) {
            $this->addText($lines, $layaway->branch->name, self::ALIGN_CENTER, $width);
        }
        if ($layaway->branch?->phone) {
            $this->addText($lines, 'Tel: '.$layaway->branch->phone, self::ALIGN_CENTER, $width);
        }

        $lines[] = ['type' => 'empty'];

        $this->addText($lines, $title, self::ALIGN_CENTER, $width, true, 'double');

        $lines[] = ['type' => 'empty'];

        $this->addText($lines, 'Apartado: '.$layaway->number, self::ALIGN_LEFT, $width);
        if ($date) {
            $this->addText($lines, 'Fecha: '.$date->format('d/m/Y H:i'), self::ALIGN_LEFT, $width);
        }
        if ($layaway->creator?->name) {
            $this->addText($lines, 'Cajero: '.$layaway->creator->name, self::ALIGN_LEFT, $width);
        }

        return $lines;
    }

    private function customerLines(Layaway $layaway, string $paperWidth): array
    {
        $lines = [];
        $width = $this->textWidth($paperWidth);

        if ($layaway->customer) {
            $this->addText($lines, 'Cliente: '.$layaway->customer->name, self::ALIGN_LEFT, $width);
            if ($layaway->customer->identification) {
                $this->addText($lines, 'Cedula: '.$layaway->customer->identification, self::ALIGN_LEFT, $width);
            }
        }

        $lines[] = ['type' => 'empty'];
        $lines[] = ['type' => 'separator'];

        return $lines;
    }

    private function detailLines(Layaway $layaway, string $paperWidth): array
    {
        $lines = [];
        $width = $this->textWidth($paperWidth);
        $is58 = $paperWidth === '58';

        foreach ($layaway->items as $item) {
            if ($is58) {
                $this->addText($lines, $item->description, self::ALIGN_LEFT, $width, true);

                if ($item->product?->internal_code) {
                    $this->addText($lines, 'Cod: '.$item->product->internal_code, self::ALIGN_LEFT, $width);
                }

                $line = $this->formatQty($item->quantity).' x '.self::CURRENCY().' '.$this->formatAmount($item->unit_price);
                if ((float) $item->tax_total > 0) {
                    $line .= ' +'.self::CURRENCY().' '.$this->formatAmount($item->tax_total);
                }
                $this->addText($lines, '  '.$line, self::ALIGN_LEFT, $width);
                $this->addText($lines, self::CURRENCY().' '.$this->formatAmount($item->total), self::ALIGN_RIGHT, $width);
            } else {
                $desc = mb_substr($item->description, 0, 30);
                $qty = str_pad($this->formatQty($item->quantity), 7, ' ', STR_PAD_LEFT);
                $price = str_pad(self::CURRENCY().' '.$this->formatAmount($item->unit_price), 12, ' ', STR_PAD_LEFT);
                $total = str_pad(self::CURRENCY().' '.$this->formatAmount($item->total), 14, ' ', STR_PAD_LEFT);
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => sprintf('%-30s %7s x %12s  %14s', $desc, $qty, $price, $total)];

                if ($item->product?->internal_code) {
                    $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => '  Cod: '.$item->product->internal_code];
                }

                if ((float) $item->tax_total > 0) {
                    $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => '  Impuesto: +'.self::CURRENCY().' '.$this->formatAmount($item->tax_total)];
                }
            }
        }

        $lines[] = ['type' => 'separator'];

        return $lines;
    }

    private function totalLines(Layaway $layaway, string $paperWidth): array
    {
        $lines = [];

        $lines[] = ['type' => 'empty'];
        $this->addText($lines, 'TOTAL APARTADO', self::ALIGN_CENTER, $this->textWidth($paperWidth, 'double'), true, 'double');
        $this->addText($lines, self::CURRENCY().' '.$this->formatAmount($layaway->total), self::ALIGN_CENTER, $this->textWidth($paperWidth, 'double'), true, 'double');
        $lines[] = ['type' => 'empty'];

        return $lines;
    }

    private function expiryLine(Layaway $layaway, string $paperWidth): array
    {
        $lines = [];
        $width = $this->textWidth($paperWidth);

        $this->addText($lines, 'Vence: '.$layaway->expires_at?->format('d/m/Y') ?? '-', self::ALIGN_LEFT, $width);

        return $lines;
    }

    private function paymentLines(?LayawayPayment $payment, Layaway $layaway, string $label, string $paperWidth): array
    {
        $lines = [];
        $align = $this->align($paperWidth);
        $width = $this->textWidth($paperWidth);

        if ($payment === null) {
            return $lines;
        }

        $this->addText($lines, $label.': '.self::CURRENCY().' '.$this->formatAmount($payment->amount), $align, $width, true);

        if ($payment->paymentMethod) {
            $this->addText($lines, 'Metodo: '.$payment->paymentMethod->name, $align, $width);
        }

        if ($payment->paymentMethod?->allows_change && $payment->received_amount !== null && $payment->change_amount !== null) {
            $this->addText($lines, '  Recibido: '.self::CURRENCY().' '.$this->formatAmount($payment->received_amount), $align, $width);
            $this->addText($lines, '  Vuelto:   '.self::CURRENCY().' '.$this->formatAmount($payment->change_amount), $align, $width);
        }

        if ($payment->reference) {
            $this->addText($lines, 'Ref: '.$payment->reference, $align, $width);
        }

        return $lines;
    }

    private function cashSessionLines(?LayawayPayment $payment, string $paperWidth): array
    {
        if ($payment === null || $payment->cashSession === null) {
            return [];
        }

        $lines = [];
        $lines[] = ['type' => 'separator'];

        $sessionInfo = [];
        if ($payment->cashSession->session_number) {
            $sessionInfo[] = $payment->cashSession->session_number;
        }
        if ($payment->cashSession->cashRegister?->name) {
            $sessionInfo[] = $payment->cashSession->cashRegister->name;
        }

        if ($sessionInfo) {
            $this->addText($lines, implode(' - ', $sessionInfo), self::ALIGN_CENTER, $this->textWidth($paperWidth), true);
        }

        return $lines;
    }

    private function footerLines(string $paperWidth): array
    {
        $lines = [];
        $lines[] = ['type' => 'empty'];
        $this->addText($lines, 'Gracias por su compra', self::ALIGN_CENTER, $this->textWidth($paperWidth));
        $lines[] = ['type' => 'empty'];

        return $lines;
    }

    private function textWidth(string $paperWidth, ?string $size = null): int
    {
        $base = $paperWidth === '58' ? self::WIDTH_58 : self::WIDTH_80;

        return $size === 'double' ? (int) floor($base / 2) : $base;
    }

    private function align(string $paperWidth): string
    {
        return $paperWidth === '58' ? self::ALIGN_LEFT : self::ALIGN_RIGHT;
    }

    private function addText(
        array &$lines,
        string $value,
        string $align,
        int $width,
        bool $emphasized = false,
        ?string $size = null,
    ): void {
        foreach ($this->wrapValue($value, $width) as $line) {
            $row = ['type' => 'text', 'align' => $align, 'value' => $line];
            if ($emphasized) {
                $row['emphasized'] = true;
            }
            if ($size !== null) {
                $row['size'] = $size;
            }
            $lines[] = $row;
        }
    }

    private function wrapValue(string $value, int $width): array
    {
        if ($value === '') {
            return [];
        }

        if (mb_strlen($value) <= $width) {
            return [$value];
        }

        $words = preg_split('/\s+/u', trim($value)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            while (mb_strlen($word) > $width) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $lines[] = mb_substr($word, 0, $width);
                $word = mb_substr($word, $width);
            }

            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $width) {
                $current .= ' '.$word;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function formatAmount(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', '.');
    }

    private function formatQty(mixed $quantity): string
    {
        $value = (float) $quantity;

        return floor($value) === $value ? number_format($value, 0, ',', '.') : number_format($value, 4, ',', '.');
    }

    private static function CURRENCY(): string
    {
        return '₡';
    }
}
