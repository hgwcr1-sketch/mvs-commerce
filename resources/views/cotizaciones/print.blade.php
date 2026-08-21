<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cotización {{ $quote->quote_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 0; padding: 24px; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { margin: 0; font-size: 20px; }
        .subtitle { font-size: 12px; color: #555; }
        .disclaimer { margin: 16px 0; padding: 10px; border: 2px dashed #b91c1c; color: #b91c1c; font-weight: 700; text-align: center; font-size: 13px; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border: 1px solid #999; padding: 6px 8px; text-align: left; }
        th { background: #eee; }
        .num { text-align: right; }
        .totals { margin-top: 12px; margin-left: auto; width: 260px; font-size: 13px; }
        .totals div { display: flex; justify-content: space-between; padding: 2px 0; }
        .totals .total { font-weight: 700; border-top: 2px solid #111; margin-top: 4px; padding-top: 4px; }
        .footer { margin-top: 32px; font-size: 11px; color: #555; text-align: center; }
        @media print { body { padding: 0; } }
    </style>
</head>
<body>

    <div class="header">
        <div>
            <h1>COTIZACIÓN</h1>
            <div class="subtitle">{{ $company->trade_name }} — {{ $quote->branch->name }}</div>
        </div>
        <div class="subtitle" style="text-align:right;">
            <div><strong>{{ $quote->quote_number }}</strong></div>
            <div>{{ optional($quote->created_at)->format('d/m/Y H:i') }}</div>
        </div>
    </div>

    <div class="disclaimer">
        ESTE DOCUMENTO ES UNA COTIZACIÓN — NO ES COMPROBANTE FISCAL
    </div>

    <div class="meta">
        <div><strong>Cliente:</strong> {{ $quote->customer?->name ?? 'Consumidor Final' }}</div>
        <div><strong>Vence:</strong> {{ $quote->expires_at ? optional($quote->expires_at)->format('d/m/Y') : 'Sin vencimiento' }}</div>
        <div><strong>Atiende:</strong> {{ $quote->user?->name }}</div>
        <div><strong>Estado:</strong> {{ $quote->isCancelled() ? 'Cancelada' : ($quote->isConverted() ? 'Convertida' : 'Activa') }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Descripción</th>
                <th class="num">Cant.</th>
                <th class="num">Precio</th>
                <th class="num">Descto.</th>
                <th class="num">Impuesto</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quote->items as $item)
                <tr>
                    <td>{{ $item->product_code ?? '—' }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ number_format((float) $item->quantity, 4, '.', '') }}</td>
                    <td class="num">{{ number_format((float) $item->unit_price, 2, '.', '') }}</td>
                    <td class="num">{{ number_format((float) $item->discount_total, 2, '.', '') }}</td>
                    <td class="num">{{ number_format((float) $item->tax_total, 2, '.', '') }}</td>
                    <td class="num">{{ number_format((float) $item->total, 2, '.', '') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span>{{ number_format((float) $quote->subtotal, 2, '.', '') }}</span></div>
        <div><span>Descuentos</span><span>{{ number_format((float) $quote->discount_total, 2, '.', '') }}</span></div>
        <div><span>Impuestos</span><span>{{ number_format((float) $quote->tax_total, 2, '.', '') }}</span></div>
        <div class="total"><span>Total</span><span>{{ number_format((float) $quote->total, 2, '.', '') }}</span></div>
    </div>

    @if($quote->notes)
        <div style="margin-top:16px;font-size:13px;"><strong>Notas:</strong> {{ $quote->notes }}</div>
    @endif

    <div class="footer">
        Generada con MVS Commerce · Este documento no constituye factura ni comprobante fiscal.
    </div>

</body>
</html>
