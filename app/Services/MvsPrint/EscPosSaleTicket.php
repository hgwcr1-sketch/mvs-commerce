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
 */
class EscPosSaleTicket
{
    private const ALIGN_CENTER = 'center';
    private const ALIGN_LEFT = 'left';
    private const ALIGN_RIGHT = 'right';

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
        $lines = array_merge($lines, $this->headerLines($data));
        $lines = array_merge($lines, $this->detailLines($data, $paperWidth));
        $lines = array_merge($lines, $this->totalsLines($data, $paperWidth));
        $lines = array_merge($lines, $this->paymentLines($data, $paperWidth));
        $lines = array_merge($lines, $this->loyaltyLines($data, $paperWidth));
        $lines = array_merge($lines, $this->cashSessionLines($data));
        $lines[] = ['type' => 'empty'];
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $data->footer_message, 'emphasized' => true];
        $lines[] = ['type' => 'empty'];

        return [
            'lines' => $lines,
            'paper_width' => $paperWidth,
            'auto_cut' => $autoCut,
            'open_drawer' => $openDrawer,
            'drawer_command' => $drawerCommand ?? EscPosTestTicket::defaultDrawerCommand(),
        ];
    }

    private function headerLines(SaleReceiptData $data): array
    {
        $lines = [];

        // Empresa vendedora - protagonista visual (trade_name)
        if ($data->company['trade_name']) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $data->company['trade_name'], 'emphasized' => true, 'size' => 'double'];
        }

        // Branding secundario plataforma (conserva diseño aprobado, tamaño normal)
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => 'MVS Commerce'];

        // Razón social
        if ($data->company['legal_name']) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $data->company['legal_name']];
        }

        // Identificación
        if ($data->company['identification_number']) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $data->company['identification_number']];
        }

        // Dirección empresa
        if ($data->company['address']) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $data->company['address']];
        }

        $lines[] = ['type' => 'empty'];

        // Sucursal
        if ($data->branch['name']) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $data->branch['name']];
        }
        if ($data->branch['phone']) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => 'Tel: ' . $data->branch['phone']];
        }
        if ($data->branch['address'] && $data->branch['address'] !== $data->company['address']) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $data->branch['address']];
        }

        $lines[] = ['type' => 'empty'];

        // Tipo documento
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $data->document['type'], 'emphasized' => true];
        $lines[] = ['type' => 'empty'];

        // Venta anulada
        if ($data->document['is_voided']) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => '*** VENTA ANULADA ***', 'emphasized' => true];
            $lines[] = ['type' => 'empty'];
        }

        // Detalles
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Venta: ' . $data->document['sale_number']];
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Fecha: ' . $data->document['completed_at']];

        // Cajero
        if ($data->cashier && isset($data->cashier['name'])) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Cajero: ' . $data->cashier['name']];
        }

        // Cliente
        if ($data->customer) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Cliente: ' . $data->customer['name']];
            if ($data->customer['identification']) {
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Cedula: ' . $data->customer['identification']];
            }
        }

        $lines[] = ['type' => 'empty'];
        $lines[] = ['type' => 'separator'];

        return $lines;
    }

    private function detailLines(SaleReceiptData $data, string $paperWidth): array
    {
        $lines = [];
        $is58 = $paperWidth === '58';

        foreach ($data->items as $item) {
            if ($is58) {
                // Formato 58mm: varias líneas por item
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => $item['description'], 'emphasized' => true];

                if ($item['product_code']) {
                    $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Cod: ' . $item['product_code']];
                }

                $line = $item['quantity'] . ' x CRC ' . $item['unit_price'];
                if ((float) $item['discount_total'] > 0) {
                    $line .= ' -CRC ' . $item['discount_total'];
                }
                if ((float) $item['tax_total'] > 0) {
                    $line .= ' +CRC ' . $item['tax_total'];
                }
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => '  ' . $line];
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_RIGHT, 'value' => 'CRC ' . $item['total']];
            } else {
                // Formato 80mm: tabla alineada
                $desc = mb_substr($item['description'], 0, 30);
                $qty = str_pad($item['quantity'], 7, ' ', STR_PAD_LEFT);
                $price = str_pad('CRC ' . $item['unit_price'], 12, ' ', STR_PAD_LEFT);
                $total = str_pad('CRC ' . $item['total'], 14, ' ', STR_PAD_LEFT);
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => sprintf('%-30s %7s x %12s  %14s', $desc, $qty, $price, $total)];

                if ($item['product_code']) {
                    $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => '  Cod: ' . $item['product_code']];
                }

                if ((float) $item['discount_total'] > 0) {
                    $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => '  Descuento: -CRC ' . $item['discount_total']];
                }
                if ((float) $item['tax_total'] > 0) {
                    $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => '  Impuesto: +CRC ' . $item['tax_total']];
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

        $lines[] = ['type' => 'text', 'align' => $align, 'value' => 'Subtotal:  CRC ' . $data->totals['subtotal']];

        if ((float) $data->totals['discount_total'] > 0) {
            $lines[] = ['type' => 'text', 'align' => $align, 'value' => 'Descuento: -CRC ' . $data->totals['discount_total']];
        }

        if ((float) $data->totals['tax_total'] > 0) {
            $lines[] = ['type' => 'text', 'align' => $align, 'value' => 'Impuesto: CRC ' . $data->totals['tax_total']];
        }

        if ((float) $data->totals['rounding_total'] !== 0.0) {
            $lines[] = ['type' => 'text', 'align' => $align, 'value' => 'Redondeo: CRC ' . $data->totals['rounding_total']];
        }

        $lines[] = ['type' => 'separator'];
        $lines[] = ['type' => 'empty'];
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => 'TOTAL', 'emphasized' => true, 'size' => 'double'];
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => 'CRC ' . $data->totals['total'], 'emphasized' => true, 'size' => 'double'];
        $lines[] = ['type' => 'empty'];
        $lines[] = ['type' => 'separator'];

        return $lines;
    }

    private function paymentLines(SaleReceiptData $data, string $paperWidth): array
    {
        $lines = [];
        $align = $paperWidth === '58' ? self::ALIGN_LEFT : self::ALIGN_RIGHT;

        foreach ($data->payments as $payment) {
            $line = $payment['method'] . ': CRC ' . $payment['amount'];

            if ($payment['reference']) {
                $line .= ' (Ref: ' . $payment['reference'] . ')';
            }

            $lines[] = ['type' => 'text', 'align' => $align, 'value' => $line];

            if ($payment['allows_change'] && $payment['received_amount'] !== null && $payment['change_amount'] !== null) {
                $lines[] = ['type' => 'text', 'align' => $align, 'value' => '  Recibido: CRC ' . $payment['received_amount']];
                $lines[] = ['type' => 'text', 'align' => $align, 'value' => '  Vuelto:   CRC ' . $payment['change_amount']];
            }
        }

        if ($data->payment_summary['is_mixed']) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => '--- Pago Mixto ---', 'emphasized' => true];
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

        if ($loyalty['kind'] === 'invitation') {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => '--- Fidelizacion ---', 'emphasized' => true];
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => 'Unete a nuestro programa de fidelidad'];

            if ($loyalty['portal_name']) {
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => $loyalty['portal_name']];
            }

            // QR de invitación - se renderiza como texto indicando QR
            if ($loyalty['show_registration_qr']) {
                $lines[] = ['type' => 'qr', 'align' => self::ALIGN_CENTER, 'value' => $loyalty['registration_url'] ?? '', 'size' => 'medium'];
                $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => 'Escanea para registrarte'];
            }

            return $lines;
        }

        $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => '--- Fidelizacion ---', 'emphasized' => true];

        if ($loyalty['kind'] === 'history' && $loyalty['balance_before'] !== null) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Saldo anterior: ' . $loyalty['balance_before']];
        }

        if ((float) ($loyalty['earned'] ?? 0) > 0) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Puntos ganados: +' . $loyalty['earned']];
        }

        if ((float) ($loyalty['redeemed'] ?? 0) > 0) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => 'Puntos canjeados: -' . $loyalty['redeemed']];
        }

        $label = $loyalty['kind'] === 'history' ? 'Saldo final' : 'Saldo actual';
        $lines[] = ['type' => 'text', 'align' => self::ALIGN_LEFT, 'value' => $label . ': ' . $loyalty['balance_after'], 'emphasized' => true];

        if ($loyalty['adjusted']) {
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => '(saldo ajustado)', 'emphasized' => false];
        }

        return $lines;
    }

    private function cashSessionLines(SaleReceiptData $data): array
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
            $lines[] = ['type' => 'text', 'align' => self::ALIGN_CENTER, 'value' => implode(' - ', $sessionInfo), 'emphasized' => true];
        }

        return $lines;
    }
}