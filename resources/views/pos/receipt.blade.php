<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante {{ $sale->sale_number }}</title>
    <style>
        *{box-sizing:border-box}html,body{height:auto}body{margin:0;background:#e2e8f0;color:#111827;font-family:Arial,Helvetica,sans-serif;line-height:1.35}
        .receipt{max-width:100%;margin:24px auto;background:#fff;padding:5mm;box-shadow:0 8px 30px #0002;height:auto;min-height:0}
        .format-80mm{width:80mm}
        .format-58mm{width:58mm;padding:3mm 3mm 2mm 3mm;font-size:12.5px}
        .format-letter{width:210mm;min-height:270mm;padding:14mm}
        h1{margin:0;font-size:20px;text-align:center}
        h2{margin:4px 0 0;font-size:13px;text-align:center;font-weight:700}
        .brand{color:#b7791f;letter-spacing:.08em}
        .center{text-align:center}
        .muted{color:#475569;font-size:11px}
        .warning{margin:8px 0;border:1px dashed #92400e;padding:7px;color:#92400e;font-size:11px;font-weight:bold;text-align:center}
        .rule{border-top:1px dashed #64748b;margin:8px 0}
        .details{display:grid;grid-template-columns:1fr;gap:3px;font-size:12px}
        table{width:100%;border-collapse:collapse;font-size:11px}
        th,td{padding:4px 3px;text-align:right;vertical-align:top;border-bottom:1px solid #e2e8f0}
        th:first-child,td:first-child{text-align:left}
        .totals{margin-left:auto;max-width:380px;width:100%}
        .totals td{font-size:11.5px}
        .grand td{border-top:2px solid #111827;font-size:22px;font-weight:900;padding-top:8px}
        .actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin:20px auto;padding:0 12px}
        .actions button,.actions a{min-height:44px;border:0;border-radius:10px;padding:11px 16px;background:#d97706;color:#fff;font-weight:bold;text-decoration:none;cursor:pointer}
        .actions a{background:#334155}
        /* 58mm legible y compacto — prueba física: letra más grande */
        .format-58mm h1{font-size:16px}
        .format-58mm h2{font-size:12px}
        .format-58mm .muted{font-size:10.5px}
        .format-58mm .details{font-size:12.5px;gap:3px}
        .format-58mm .warning{font-size:11px;padding:6px}
        .format-58mm .rule{margin:6px 0}
        .format-58mm .totals{max-width:100%;margin:0}
        .format-58mm .totals td{font-size:12.5px;padding:3px 0}
        .format-58mm .grand td{font-size:23px}
        /* items 58mm : 2 líneas - ancho completo */
        .items58{border-top:1px solid #e2e8f0}
        .item58{padding:7px 0;border-bottom:1px solid #e2e8f0;page-break-inside:avoid}
        .item58-name{font-size:13.5px;font-weight:800;line-height:1.3;word-break:break-word;color:#111827}
        .item58-name .muted{display:block;font-size:10px;font-weight:400;margin-top:1px}
        .item58-line{display:flex;justify-content:space-between;gap:6px;font-size:12px;margin-top:3px;align-items:flex-start}
        .item58-line .left{color:#334155;flex:1;word-break:break-word}
        .item58-line .right{font-weight:800;white-space:nowrap;color:#111827;font-size:12.5px}
        .format-58mm .pay-table td,.format-58mm .loyalty-table td{font-size:12.5px;padding:3px 0}
        .format-58mm .thanks{margin-top:8px;font-size:12.5px;font-weight:700}
        .format-58mm .thanks + .cut-tail{height:10mm}
        .format-80mm .thanks + .cut-tail{height:10mm}
        .format-letter .details{grid-template-columns:repeat(2,1fr);gap:6px}
        .format-letter table{font-size:13px}
        .format-letter th,.format-letter td{padding:9px 6px}
        .format-letter h1{font-size:26px}
        @media(max-width:767px){.receipt{margin:0 auto;box-shadow:none}.format-letter{width:100%;min-height:0;padding:6mm}.format-letter .details{grid-template-columns:1fr}.actions{flex-direction:column}.actions a,.actions button{text-align:center}}
        @page{margin:0}
        @media print{body{background:#fff}.receipt{margin:0;box-shadow:none}.actions{display:none}
        .format-58mm{width:58mm}
        .format-80mm{width:80mm}
        .format-letter{width:100%;min-height:auto}
        .receipt{page-break-after:auto}
        }
    </style>
    @if($format === '58mm')<style>@media print{@page{size:58mm auto;margin:0} html,body{width:58mm;margin:0;padding:0} .receipt{width:58mm;margin:0;padding:3mm 3mm 2mm 3mm} }</style>@endif
    @if($format === '80mm')<style>@media print{@page{size:80mm auto;margin:0} html,body{width:80mm;margin:0;padding:0} .receipt{width:80mm;margin:0} }</style>@endif
</head>
<body>
<main class="receipt format-{{ $format }}" data-receipt-format="{{ $format }}">
    <header>
        <h1 class="brand">MVS COMMERCE</h1>
        <h2 class="center">{{ $company->trade_name }}</h2>
        <p class="center muted">{{ $company->legal_name }}<br>{{ $company->identification_number }}<br>{{ $sale->branch->name }} · {{ $sale->branch->phone }}<br>{{ $sale->branch->address ?: $company->address }}</p>
    </header>
    <div class="center" style="margin:7px 0 6px;font-size:11px;font-weight:800;letter-spacing:.08em;border:1px solid #111827;padding:5px 6px;">TICKET ELECTRÓNICO</div>
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
    @if($format === '58mm')
        <div class="items58">
        @foreach($sale->items as $item)
            <div class="item58">
                <div class="item58-name">{{ $item->description }} @if($item->product_code)<span class="muted">{{ $item->product_code }}</span>@endif</div>
                <div class="item58-line">
                    <span class="left">{{ rtrim(rtrim(number_format((float) $item->quantity, 4, ',', '.'), '0'), ',') }} x ₡{{ number_format((float) $item->unit_price, 0, ',', '.') }}@if((float) $item->discount_total > 0) -₡{{ number_format((float) $item->discount_total, 0, ',', '.') }}@endif @if((float) $item->tax_total > 0) +₡{{ number_format((float) $item->tax_total, 0, ',', '.') }}@endif</span>
                    <span class="right">₡{{ number_format((float) $item->total, 0, ',', '.') }}</span>
                </div>
            </div>
        @endforeach
        </div>
    @else
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
    @endif
    @if($loyalty ?? null)
        <div class="rule"></div>
        <section aria-label="Fidelización">
            <p><strong>Fidelización</strong>@if($loyalty['adjusted']) — saldo ajustado posteriormente @endif</p>
            <table class="{{ $format === '58mm' ? 'loyalty-table' : '' }}">
                <tr><td>Puntos ganados</td><td>+{{ number_format((float) $loyalty['earned'], 2, ',', '.') }}</td></tr>
                <tr><td>Puntos utilizados</td><td>-{{ number_format((float) $loyalty['redeemed'], 2, ',', '.') }}</td></tr>
                <tr><td>Saldo anterior</td><td>{{ number_format((float) $loyalty['balance_before'], 2, ',', '.') }}</td></tr>
                <tr><td>Saldo actual</td><td><strong>{{ number_format((float) $loyalty['balance_after'], 2, ',', '.') }}</strong></td></tr>
            </table>
        </section>
    @endif
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
    <table class="{{ $format === '58mm' ? 'pay-table' : '' }}">
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
    <p class="center muted thanks">Gracias por su compra</p>
    @if(in_array($format, ['58mm','80mm'], true))<div class="cut-tail"></div>@endif
</main>
@unless($pdfMode ?? false)
    @if(session('success'))
        <p class="center">{{ session('success') }}</p>
    @endif
    @if($errors->has('email'))
        <p class="center warning">{{ $errors->first('email') }}</p>
    @endif
    <form class="actions" method="POST" action="{{ route('pos.receipt.mail', $sale) }}">
        @csrf
        <label class="muted" for="receipt-email">Correo del cliente</label>
        <input id="receipt-email" type="email" name="email" required maxlength="150" value="{{ old('email', $sale->customer?->email) }}" placeholder="cliente@correo.com" style="min-height:44px;padding:10px;border:1px solid #94a3b8;border-radius:10px">
        <button type="submit">Enviar comprobante</button>
    </form>
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
