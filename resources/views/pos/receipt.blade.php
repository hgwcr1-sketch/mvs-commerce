<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante {{ $sale->sale_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #e2e8f0; color: #111827; font-family: Arial, sans-serif; }
        .receipt { width: 80mm; max-width: 100%; margin: 24px auto; background: white; padding: 5mm; box-shadow: 0 8px 30px #0002; }
        h1 { margin: 0; font-size: 18px; text-align: center; }
        .center { text-align: center; }
        .muted { color: #475569; font-size: 11px; }
        .warning { margin: 10px 0; border: 1px dashed #92400e; padding: 8px; color: #92400e; font-size: 11px; font-weight: bold; text-align: center; }
        .rule { border-top: 1px dashed #64748b; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { padding: 4px 1px; text-align: right; vertical-align: top; }
        th:first-child, td:first-child { text-align: left; }
        .totals td { font-size: 12px; }
        .grand td { border-top: 1px solid #111827; font-size: 16px; font-weight: bold; }
        .actions { display: flex; gap: 8px; justify-content: center; margin: 20px auto; }
        .actions button, .actions a { border: 0; border-radius: 8px; padding: 10px 16px; background: #d97706; color: white; font-weight: bold; text-decoration: none; cursor: pointer; }
        .actions a { background: #334155; }
        @page { size: 80mm auto; margin: 0; }
        @media print {
            body { background: white; }
            .receipt { margin: 0; box-shadow: none; width: 80mm; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
<main class="receipt">
    <h1>{{ $company->trade_name }}</h1>
    <p class="center muted">{{ $sale->branch->name }}<br>{{ $company->identification_number }}</p>
    <div class="warning">Comprobante interno — pendiente de integración con Hacienda</div>
    @if($sale->status === \App\Models\Sale::STATUS_VOIDED)
        <div class="warning">VENTA ANULADA</div>
    @endif
    <p><strong>{{ $sale->sale_number }}</strong><br>
        <span class="muted">{{ $sale->completed_at?->timezone($company->timezone)->format('d/m/Y H:i') }}</span><br>
        Cajero: {{ $sale->user->name }}<br>
        Cliente: {{ $sale->customer?->name ?? 'Consumidor Final' }}
    </p>
    <div class="rule"></div>
    <table>
        <thead><tr><th>Producto</th><th>Cant.</th><th>Precio</th><th>Imp.</th><th>Total</th></tr></thead>
        <tbody>
        @foreach($sale->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td>{{ rtrim(rtrim(number_format((float) $item->quantity, 4, ',', '.'), '0'), ',') }}</td>
                <td>₡{{ number_format((float) $item->unit_price, 0, ',', '.') }}</td>
                <td>₡{{ number_format((float) $item->tax_total, 0, ',', '.') }}</td>
                <td>₡{{ number_format((float) $item->total, 0, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="rule"></div>
    <table class="totals">
        <tr><td>Subtotal</td><td>₡{{ number_format((float) $sale->subtotal, 0, ',', '.') }}</td></tr>
        <tr><td>Impuesto</td><td>₡{{ number_format((float) $sale->tax_total, 0, ',', '.') }}</td></tr>
        @if((float) $sale->rounding_total !== 0.0)
            <tr><td>Ajuste por redondeo</td><td>₡{{ number_format((float) $sale->rounding_total, 0, ',', '.') }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td>₡{{ number_format((float) $sale->total, 0, ',', '.') }}</td></tr>
        <tr><td>Efectivo recibido</td><td>₡{{ number_format((float) $sale->payments->first()?->received_amount, 0, ',', '.') }}</td></tr>
        <tr><td>Vuelto</td><td>₡{{ number_format((float) $sale->payments->first()?->change_amount, 0, ',', '.') }}</td></tr>
    </table>
    <div class="rule"></div>
    <p class="center"><strong>Sin sesión de caja</strong></p>
</main>
<div class="actions">
    <button type="button" onclick="window.print()">Imprimir</button>
    <a href="{{ route('pos.index') }}">Volver al POS</a>
</div>
</body>
</html>
