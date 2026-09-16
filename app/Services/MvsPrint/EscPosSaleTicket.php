<?php

namespace App\Services\MvsPrint;

use App\Services\Sales\SaleReceiptData;

/**
 * Construye el payload ESC/POS del ticket de una venta ya completada.
 *
 * Consume SaleReceiptData (fuente única de datos) para garantizar paridad
 * con el recibo HTML. El backend solo prepara comandos de alto nivel
 * (líneas, corte y cajón); el navegador (qz.js) los convierte en bytes
 * ESC/POS y los envía a QZ Tray.
 *
 * Notas visuales 1.0.2:
 * - La moneda se entrega como símbolo colón (₡, U+20A1); qz.js lo traduce a
 *   un byte de una sola página de códigos (CP850/CP437, nunca UTF-8 crudo)
 *   para que la POS-58-Series nunca muestre mojibake.
 * - Todo texto libre pasa por word-wrap consciente de espacios: ninguna
 *   palabra normal se corta a mitad si cabe completa en la siguiente línea;
 *   solo se divide una palabra si supera el ancho total disponible. 58mm usa
 *   un máximo más angosto que 80mm (fuente A).
 */
class EscPosSaleTicket
{
    private const ALIGN_CENTER = 'center';

    private const ALIGN_LEFT = 'left';

    private const ALIGN_RIGHT = 'right';

    /** Moneda visual del comprobante: colón costarricense (₡). */
    private const CURRENCY = '₡';

    /** Ancho aproximado en caracteres de fuente A para cada papel. */
    private const WIDTH_58 = 32;

    private const WIDTH_80 = 48;

    /**
     * Construye el descriptor del ticket de venta para la impresora ESC/POS.
     *
     * @param  SaleReceiptData  $data  Datos normalizados del comprobante
     * @param  string  $paperWidth  '58' o '80'
     * @param  bool  $autoCut  Cortar después de imprimir
     * @param  bool  $openDrawer  Abrir cajón después de imprimir
     * @param  array|null  $drawerCommand  Comando ESC/POS de apertura
     */
    public function build(
        SaleReceiptData $data,
        string $paperWidth = '80',
        bool $autoCut = true,
        bool $openDrawer = false,
        ?array $drawerCommand = null,
    ): array {
        $lines = [];
        $lines = array_merge($lines, $this->headerLines($data, $paperWidth));
        $lines = array_merge($lines, $this->detailLines($data, $paperWidth));
        $lines = array_merge($lines, $this->totalsLines($data, $paperWidth));
        $lines = array_merge($lines, $this->paymentLines($data, $paperWidth));
        $lines = array_merge($lines, $this->loyaltyLines($data, $paperWidth));
        $lines = array_merge($lines, $this->cashSessionLines($data, $paperWidth));
        $lines[] = ['type' => 'empty'];
        $this->addText($lines, $data->footer_message ?? '', self::ALIGN_CENTER, $this->textWidth($paperWidth), true);
        $lines[] = ['type' => 'empty'];

        return [
            'lines' => $lines,
            'paper_width' => $paperWidth,
            'auto_cut' => $autoCut,
            'open_drawer' => $openDrawer,
            'drawer_command' => $drawerCommand ?? EscPosTestTicket::defaultDrawerCommand(),
        ];
    }

    private function textWidth(string $paperWidth, ?string $size = null): int
    {
        $base = $paperWidth === '58' ? self::WIDTH_58 : self::WIDTH_80;

        return $size === 'double' ? (int) floor($base / 2) : $base;
    }

    /**
     * Agrega una línea de texto ya ajustada al ancho (word-wrap).
     */
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

    /**
     * Word-wrap: corta en espacios y nunca divide una palabra normal.
     * Solo divide una palabra si supera el ancho disponible completo.
     */
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
            // Palabra más ancha que toda la línea: se divide sin perder datos.
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

    private function headerLines(SaleReceiptData $data, string $paperWidth): array
    {
        $lines = [];
        $width = $this->textWidth($paperWidth);

        // Empresa vendedora - protagonista visual (trade_name)
        if ($data->company['trade_name']) {
            $this->addText($lines, $data->company['trade_name'], self::ALIGN_CENTER, $this->textWidth($paperWidth, 'double'), true, 'double');
        }

        // Branding secundario plataforma (conserva diseño aprobado, tamaño normal)
        $this->addText($lines, 'MVS Commerce', self::ALIGN_CENTER, $width);

        // Razón social
        if ($data->company['legal_name']) {
            $this->addText($lines, $data->company['legal_name'], self::ALIGN_CENTER, $width);
        }

        // Identificación
        if ($data->company['identification_number']) {
            $this->addText($lines, $data->company['identification_number'], self::ALIGN_CENTER, $width);
        }

        // Dirección empresa
        if ($data->company['address']) {
            $this->addText($lines, $data->company['address'], self::ALIGN_CENTER, $width);
        }

        $lines[] = ['type' => 'empty'];

        // Sucursal
        if ($data->branch['name']) {
            $this->addText($lines, $data->branch['name'], self::ALIGN_CENTER, $width);
        }
        if ($data->branch['phone']) {
            $this->addText($lines, 'Tel: '.$data->branch['phone'], self::ALIGN_CENTER, $width);
        }
        if ($data->branch['address'] && $data->branch['address'] !== $data->company['address']) {
            $this->addText($lines, $data->branch['address'], self::ALIGN_CENTER, $width);
        }

        $lines[] = ['type' => 'empty'];

        // Tipo documento
        $this->addText($lines, $data->document['type'], self::ALIGN_CENTER, $width, true);

        $lines[] = ['type' => 'empty'];

        // Venta anulada
        if ($data->document['is_voided']) {
            $this->addText($lines, '*** VENTA ANULADA ***', self::ALIGN_CENTER, $width, true);
            $lines[] = ['type' => 'empty'];
        }

        // Detalles
        $this->addText($lines, 'Venta: '.$data->document['sale_number'], self::ALIGN_LEFT, $width);
        $this->addText($lines, 'Fecha: '.$data->document['completed_at'], self::ALIGN_LEFT, $width);

        // Cajero
        if ($data->cashier && isset($data->cashier['name'])) {
            $this->addText($lines, 'Cajero: '.$data->cashier['name'], self::ALIGN_LEFT, $width);
        }

        // Cliente
        if ($data->customer) {
            $this->addText($lines, 'Cliente: '.$data->customer['name'], self::ALIGN_LEFT, $width);
            if ($data->customer['identification']) {
                $this->addText($lines, 'Cedula: '.$data->customer['identification'], self::ALIGN_LEFT, $width);
            }
        }

        $lines[] = ['type' => 'empty'];
        $lines[] = ['type' => 'separator'];

        return $lines;
    }

    private function detailLines(SaleReceiptData $data, string $paperWidth): array
    {
        $lines = [];
        $width = $this->textWidth($paperWidth);
        $is58 = $paperWidth === '58';

        foreach ($data->items as $item) {
            if ($is58) {
                // Formato 58mm: varias líneas por item
                $this->addText($lines, $item['description'], self::ALIGN_LEFT, $width, true);

                if ($item['product_code']) {
                    $this->addText($lines, 'Cod: '.$item['product_code'], self::ALIGN_LEFT, $width);
                }

                $line = $item['quantity'].' x '.self::CURRENCY.' '.$item['unit_price'];
                if ((float) $item['discount_total'] > 0) {
                    $line .= ' -'.self::CURRENCY.' '.$item['discount_total'];
                }
                if ((float) $item['tax_total'] > 0) {
                    $line .= ' +'.self::CURRENCY.' '.$item['tax_total'];
                }
                $this->addText($lines, '  '.$line, self::ALIGN_LEFT, $width);
                $this->addText($lines, self::CURRENCY.' '.$item['total'], self::ALIGN_RIGHT, $width);
            } else {
                // Formato 80mm: tabla alineada
                $desc = mb_substr($item['description'], 0, 30);
                $qty = str_pad($item['quantity'], 7, ' ', STR_PAD_LEFT);
                $price = str_pad(self::CURRENCY.' '.$item['unit_price'], 12, ' ', STR_PAD_LEFT);
                $total = str_pad(self::CURRENCY.' '.$item['total'], 14, ' ', STR_PAD_LEFT);
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => sprintf('%-30s %7s x %12s  %14s', $desc, $qty, $price, $total)];

                if ($item['product_code']) {
                    $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => '  Cod: '.$item['product_code']];
                }

                if ((float) $item['discount_total'] > 0) {
                    $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => '  Descuento: -'.self::CURRENCY.' '.$item['discount_total']];
                }
                if ((float) $item['tax_total'] > 0) {
                    $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => '  Impuesto: +'.self::CURRENCY.' '.$item['tax_total']];
                }
            }
        }

        $lines[] = ['type' => 'separator'];

        return $lines;
    }

    private function totalsLines(SaleReceiptData $data, string $paperWidth): array
    {
        $lines = [];
        $align = $paperWidth === '58' ? self::ALIGN_LEFT : self::ALIGN_RIGHT;
        $width = $this->textWidth($paperWidth);

        $this->addText($lines, 'Subtotal:  '.self::CURRENCY.' '.$data->totals['subtotal'], $align, $width);

        if ((float) $data->totals['discount_total'] > 0) {
            $this->addText($lines, 'Descuento: -'.self::CURRENCY.' '.$data->totals['discount_total'], $align, $width);
        }

        if ((float) $data->totals['tax_total'] > 0) {
            $this->addText($lines, 'Impuesto: '.self::CURRENCY.' '.$data->totals['tax_total'], $align, $width);
        }

        if ((float) $data->totals['rounding_total'] !== 0.0) {
            $this->addText($lines, 'Redondeo: '.self::CURRENCY.' '.$data->totals['rounding_total'], $align, $width);
        }

        $lines[] = ['type' => 'separator'];
        $lines[] = ['type' => 'empty'];
        $this->addText($lines, 'TOTAL', self::ALIGN_CENTER, $this->textWidth($paperWidth, 'double'), true, 'double');
        $this->addText($lines, self::CURRENCY.' '.$data->totals['total'], self::ALIGN_CENTER, $this->textWidth($paperWidth, 'double'), true, 'double');
        $lines[] = ['type' => 'empty'];
        $lines[] = ['type' => 'separator'];

        return $lines;
    }

    private function paymentLines(SaleReceiptData $data, string $paperWidth): array
    {
        $lines = [];
        $align = $paperWidth === '58' ? self::ALIGN_LEFT : self::ALIGN_RIGHT;
        $width = $this->textWidth($paperWidth);

        foreach ($data->payments as $payment) {
            $line = $payment['method'].': '.self::CURRENCY.' '.$payment['amount'];

            if ($payment['reference']) {
                $line .= ' (Ref: '.$payment['reference'].')';
            }

            $this->addText($lines, $line, $align, $width);

            if ($payment['allows_change'] && $payment['received_amount'] !== null && $payment['change_amount'] !== null) {
                $this->addText($lines, '  Recibido: '.self::CURRENCY.' '.$payment['received_amount'], $align, $width);
                $this->addText($lines, '  Vuelto:   '.self::CURRENCY.' '.$payment['change_amount'], $align, $width);
            }
        }

        if ($data->payment_summary['is_mixed']) {
            $this->addText($lines, '--- Pago Mixto ---', self::ALIGN_CENTER, $width, true);
        }

        $lines[] = ['type' => 'separator'];

        return $lines;
    }

    private function loyaltyLines(SaleReceiptData $data, string $paperWidth): array
    {
        if ($data->loyalty === null) {
            return [];
        }

        $loyalty = $data->loyalty;
        $lines = [];
        $width = $this->textWidth($paperWidth);

        if ($loyalty['kind'] === 'invitation') {
            $this->addText($lines, '--- Fidelizacion ---', self::ALIGN_CENTER, $width, true);
            $this->addText($lines, 'Unete a nuestro programa de fidelidad', self::ALIGN_CENTER, $width);

            if ($loyalty['portal_name']) {
                $this->addText($lines, $loyalty['portal_name'], self::ALIGN_CENTER, $width);
            }

            // QR de invitación - se renderiza como texto indicando QR
            if ($loyalty['show_registration_qr']) {
                $lines[] = ['type' => 'qr', 'align' => self::ALIGN_CENTER, 'value' => $loyalty['registration_url'] ?? '', 'size' => 'medium'];
                $this->addText($lines, 'Escanea para registrarte', self::ALIGN_CENTER, $width);
            }

            return $lines;
        }

        $this->addText($lines, '--- Fidelizacion ---', self::ALIGN_CENTER, $width, true);

        if ($loyalty['kind'] === 'history' && $loyalty['balance_before'] !== null) {
            $this->addText($lines, 'Saldo anterior: '.$loyalty['balance_before'], self::ALIGN_LEFT, $width);
        }

        if ((float) ($loyalty['earned'] ?? 0) > 0) {
            $this->addText($lines, 'Puntos ganados: +'.$loyalty['earned'], self::ALIGN_LEFT, $width);
        }

        if ((float) ($loyalty['redeemed'] ?? 0) > 0) {
            $this->addText($lines, 'Puntos canjeados: -'.$loyalty['redeemed'], self::ALIGN_LEFT, $width);
        }

        $label = $loyalty['kind'] === 'history' ? 'Saldo final' : 'Saldo actual';
        $this->addText($lines, $label.': '.$loyalty['balance_after'], self::ALIGN_LEFT, $width, true);

        if ($loyalty['adjusted']) {
            $this->addText($lines, '(saldo ajustado)', self::ALIGN_CENTER, $width);
        }

        return $lines;
    }

    private function cashSessionLines(SaleReceiptData $data, string $paperWidth): array
    {
        if ($data->cash_session === null) {
            return [];
        }

        $lines = [];
        $lines[] = ['type' => 'separator'];

        $sessionInfo = [];
        if ($data->cash_session['session_number']) {
            $sessionInfo[] = $data->cash_session['session_number'];
        }
        if ($data->cash_session['cash_register_name']) {
            $sessionInfo[] = $data->cash_session['cash_register_name'];
        }

        if ($sessionInfo) {
            $this->addText($lines, implode(' - ', $sessionInfo), self::ALIGN_CENTER, $this->textWidth($paperWidth), true);
        }

        return $lines;
    }
}
