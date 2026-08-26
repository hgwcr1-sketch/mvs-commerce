<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante {{ $sale->sale_number }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#e2e8f0;color:#111827;font-family:Arial,sans-serif}.receipt{max-width:100%;margin:24px auto;background:#fff;padding:5mm;box-shadow:0 8px 30px #0002}.format-80mm{width:80mm}.format-58mm{width:58mm;padding:3mm}.format-letter{width:210mm;min-height:270mm;padding:14mm}h1{margin:0;font-size:20px;text-align:center}.brand{color:#b7791f;letter-spacing:.08em}.center{text-align:center}.muted{color:#475569;font-size:11px}.warning{margin:10px 0;border:1px dashed #92400e;padding:8px;color:#92400e;font-size:11px;font-weight:bold;text-align:center}.rule{border-top:1px dashed #64748b;margin:10px 0}.details{display:grid;grid-template-columns:1fr;gap:2px;font-size:12px}table{width:100%;border-collapse:collapse;font-size:11px}th,td{padding:4px 2px;text-align:right;vertical-align:top;border-bottom:1px solid #e2e8f0}th:first-child,td:first-child{text-align:left}.totals{margin-left:auto;max-width:380px}.grand td{border-top:2px solid #111827;font-size:22px;font-weight:900;padding-top:9px}.actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin:20px auto;padding:0 12px}.actions button,.actions a{min-height:44px;border:0;border-radius:10px;padding:11px 16px;background:#d97706;color:#fff;font-weight:bold;text-decoration:none;cursor:pointer}.actions a{background:#334155}.format-58mm h1{font-size:16px}.format-58mm table{font-size:9px}.format-58mm th,.format-58mm td{padding:3px 1px}.format-58mm .grand td{font-size:17px}.format-letter .details{grid-template-columns:repeat(2,1fr);gap:6px}.format-letter table{font-size:13px}.format-letter th,.format-letter td{padding:9px 6px}.format-letter h1{font-size:26px}@media(max-width:767px){.receipt{margin:0 auto;box-shadow:none}.format-letter{width:100%;min-height:0;padding:6mm}.format-letter .details{grid-template-columns:1fr}.actions{flex-direction:column}.actions a,.actions button{text-align:center}}@page{margin:0}@media print{body{background:#fff}.receipt{margin:0;box-shadow:none}.actions{display:none}.format-80mm{width:80mm}.format-58mm{width:58mm}.format-letter{width:100%;min-height:auto}}
    </style>
</head>
<body>
<main class="receipt format-{{ $format }}" data-receipt-format="{{ $format }}">
    <header>
        <h1 class="brand">MVS COMMERCE</h1>
        <h2 class="center">{{ $company->trade_name }}</h2>
        <p class="center muted">{{ $company->legal_name }}<br>{{ $company->identification_number }}<br>{{ $sale->branch->name }} · {{ $sale->branch->phone }}<br>{{ $sale->branch->address ?: $company->address }}</p>
    </header>
    <div class="warning">Comprobante interno — pendiente de integración con Hacienda</div>
    @if($sale->status === \App\Models\Sale::STATUS_VOIDED)
        <div class="warning">VENTA ANULADA</div>
    @endif
    <section class="details">
        <span><strong>Comprobante:</strong> {{ $sale->sale_number }}</span>
        <span><strong>Fecha:</strong> {{ $sale->completed_at?->timezone($company->timezone)->format('d/m/Y H:i') }}</span>
        <span><strong>Cajero:</strong> {{ $sale->user->name }}</span>
        <span><strong>Cliente:</strong> {{ $sale->customer?->name ?? 'Consumidor Final' }}</span>
    </section>
    <div class="rule"></div>
    <table>
        <thead><tr><th>Producto</th><th>Cant.</th><th>Precio</th><th>Desc.</th><th>Imp.</th><th>Total</th></tr></thead>
        <tbody>
        @foreach($sale->items as $item)
            <tr>
                <td>{{ $item->description }}<br><span class="muted">{{ $item->product_code }}</span></td>
                <td>{{ rtrim(rtrim(number_format((float) $item->quantity, 4, ',', '.'), '0'), ',') }}</td>
                <td>₡{{ number_format((float) $item->unit_price, 0, ',', '.') }}</td>
                <td>₡{{ number_format((float) $item->discount_total, 0, ',', '.') }}</td>
                <td>₡{{ number_format((float) $item->tax_total, 0, ',', '.') }}</td>
                <td>₡{{ number_format((float) $item->total, 0, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="rule"></div>
    <table class="totals">
        <tr><td>Subtotal</td><td>₡{{ number_format((float) $sale->subtotal, 0, ',', '.') }}</td></tr>
        @if((float) $sale->discount_total > 0)
            <tr><td>Descuento</td><td>-₡{{ number_format((float) $sale->discount_total, 0, ',', '.') }}</td></tr>
        @endif
        <tr><td>Impuesto</td><td>₡{{ number_format((float) $sale->tax_total, 0, ',', '.') }}</td></tr>
        @if((float) $sale->rounding_total !== 0.0)
            <tr><td>Redondeo</td><td>₡{{ number_format((float) $sale->rounding_total, 0, ',', '.') }}</td></tr>
        @endif
        <tr class="grand"><td>TOTAL</td><td>₡{{ number_format((float) $sale->total, 0, ',', '.') }}</td></tr>
    </table>
    <div class="rule"></div>
    <p><strong>Formas de pago</strong>@if($sale->payments->count() >= 2) — Pago mixto @endif</p>
    <table>
        @foreach($sale->payments as $payment)
            <tr><td>{{ $payment->paymentMethod->name }}</td><td>₡{{ number_format((float) $payment->amount, 0, ',', '.') }}</td></tr>
            @if($payment->reference)
                <tr><td class="muted">Referencia</td><td class="muted">{{ $payment->reference }}</td></tr>
            @endif
            @if($payment->paymentMethod->allows_change)
                <tr><td class="muted">Recibido / vuelto</td><td class="muted">₡{{ number_format((float) $payment->received_amount, 0, ',', '.') }} / ₡{{ number_format((float) $payment->change_amount, 0, ',', '.') }}</td></tr>
            @endif
        @endforeach
    </table>
    <div class="rule"></div>
    <p class="center"><strong>{{ $sale->cashSession ? $sale->cashSession->session_number.' — '.$sale->cashSession->cashRegister->name : 'Sin sesión de caja' }}</strong></p>
    <p class="center muted">Gracias por su compra</p>
</main>
@unless($pdfMode ?? false)
    <nav class="actions" aria-label="Acciones del comprobante">
        <button type="button" onclick="window.print()">Imprimir</button>
        @foreach(['80mm' => '80 mm', '58mm' => '58 mm', 'letter' => 'Grande'] as $value => $label)
            <a href="{{ route('pos.receipt', $sale) }}?format={{ $value }}">{{ $label }}</a>
        @endforeach
        <a href="{{ route('pos.receipt.pdf', $sale) }}?format={{ $format }}">Descargar PDF</a>
        <a href="{{ auth()->user()->hasPermission('ventas.ver', $company) ? route('ventas.show', $sale) : route('pos.index') }}">Volver</a>
    </nav>
@endunless
@if(!($pdfMode ?? false) && $autoPrint)
    <script>window.addEventListener('load',()=>window.print());</script>
@endif
</body>
</html>
