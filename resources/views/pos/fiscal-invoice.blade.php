<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Factura Electrónica {{ $sale->sale_number }}</title>
    <style>
        *{box-sizing:border-box}
        html,body{height:auto}
        body{margin:0;background:#fff;color:#111827;font-family:'DejaVu Sans',Arial,Helvetica,sans-serif;font-size:9.5px;line-height:1.35}
        .page{width:190mm;padding:9mm 10mm 7mm;background:#fff;box-sizing:content-box}
        .clr{clear:both;height:0;overflow:hidden}
        .brand-col{width:76mm;float:left}
        .brand-logo{display:block;max-height:15mm;max-width:40mm;margin-bottom:2mm}
        .company-name{font-size:16px;font-weight:700;color:#111827}
        .company-legal{font-size:9px;color:#475569;margin-top:1mm;line-height:1.5}
        .doc-col{width:96mm;float:right;border:1px solid #D4AF37;border-top:3px solid #D4AF37;padding:2.5mm 3mm}
        .doc-title{font-size:14px;font-weight:700;letter-spacing:.1em;text-align:center;margin:0 0 1.5mm;color:#111827}
        .doc-title .gold{color:#B1922D}
        .doc-table{width:100%;border-collapse:collapse}
        .doc-table td{padding:.7mm 0;font-size:9px;vertical-align:top}
        .doc-table td.lbl{color:#334155;font-weight:700;width:30mm}
        .doc-table td.full{font-size:9px}
        .mono{font-family:'DejaVu Sans Mono',monospace;font-size:8.5px;word-break:break-all}
        .badge{display:inline-block;font-weight:700;font-size:8.5px;padding:.6mm 2mm;border:1px solid currentColor}
        .badge.ok{color:#15803d}
        .badge.warn{color:#b45309}
        .badge.bad{color:#b91c1c}
        .section{margin-top:3mm}
        .section-label{font-size:8.5px;font-weight:700;letter-spacing:.14em;color:#B1922D;text-transform:uppercase;border-bottom:1px solid #D4AF37;padding-bottom:.8mm;margin-bottom:1.5mm}
        .bill-table{width:100%;border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0}
        .bill-table td{width:33%;padding:2mm 3mm;font-size:9.5px;border-right:1px solid #e2e8f0}
        .bill-table td:last-child{border-right:0}
        .bill-table b{display:block;font-size:8px;letter-spacing:.08em;color:#64748b;text-transform:uppercase;font-weight:700}
        table.items{width:100%;border-collapse:collapse}
        table.items th{background:#111827;color:#fff;font-size:8.5px;letter-spacing:.06em;text-transform:uppercase;padding:1.6mm 2mm;text-align:right}
        table.items th:first-child{text-align:left}
        table.items td{padding:1.6mm 2mm;border-bottom:1px solid #e2e8f0;vertical-align:top;text-align:right}
        table.items td:first-child{text-align:left}
        .item-name{font-weight:700}
        .item-cabys{display:block;font-size:8px;color:#64748b;margin-top:.5mm}
        .right-col{width:84mm;float:right}
        .left-col{width:86mm;float:left}
        .loyalty{background:#f8fafc;border-left:3px solid #D4AF37;padding:1.6mm 3mm;font-size:9px}
        table.totals{width:100%;border-collapse:collapse}
        table.totals td{padding:1.1mm 2mm;font-size:9.5px}
        table.totals td:last-child{text-align:right}
        table.totals .muted td{color:#475569}
        table.totals .strong td{font-weight:700;border-top:1px solid #cbd5e1}
        table.totals .grand td{font-size:14px;font-weight:700;border-top:2px solid #111827;padding-top:1.6mm}
        table.totals .grand .amount{color:#111827;border-left:3px solid #D4AF37}
        .words{margin-top:1.2mm;font-size:8.5px;font-weight:700;text-align:right;color:#334155;line-height:1.4}
        .issuer-table{width:100%;border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0}
        .issuer-table td{padding:1.6mm 3mm;font-size:8.5px;vertical-align:top;width:25%}
        .issuer-table b{display:block;font-size:7.5px;letter-spacing:.08em;color:#64748b;text-transform:uppercase}
        .legal{width:130mm;float:left;font-size:7.5px;color:#475569;line-height:1.45}
        .legal p{margin:.4mm 0}
        .qr-block{width:32mm;float:right;text-align:center}
        .qr-block img{display:block;width:26mm;height:26mm;margin:0 auto .8mm}
        .qr-block .qr-cap{font-size:7px;color:#475569}
        .brand-footer{clear:both;margin-top:3mm;border-top:1px solid #e2e8f0;padding-top:1.6mm;text-align:center}
        .brand-footer img{height:6mm;vertical-align:middle}
        .brand-footer span{font-size:8.5px;color:#64748b;vertical-align:middle}
        .actions{clear:both;text-align:center;padding:6mm 0 0}
        .actions button,.actions a{min-height:44px;border:0;border-radius:10px;padding:11px 16px;background:#D4AF37;color:#111827;font-weight:bold;text-decoration:none;cursor:pointer}
        .actions a{background:#334155;color:#fff}
        @page{margin:0}
        @media print{body{background:#fff}.page{padding:7mm 8mm 5mm}.actions{display:none}}
        @media(max-width:767px){.page{width:100%;padding:5mm}.brand-col,.doc-col,.left-col,.right-col,.legal,.qr-block{width:100%;float:none}.doc-col{margin-top:3mm}.right-col{margin-top:2mm}}
    </style>
</head>
<body>
@php
    $data = $receiptData;
    $f = $data->fiscal;
    $co = $data->company;
    $br = $data->branch;
    $cust = $data->customer;
    $doc = $data->document;
    $money = static fn ($amount) => number_format((float) $amount, 2, ',', '.');
    $subtotal = (float) $sale->subtotal;
    $discount = (float) $sale->discount_total;
    $taxTotal = (float) $sale->tax_total;
    $rounding = (float) $sale->rounding_total;
    $total = (float) $sale->total;
    $taxGroups = $sale->items->groupBy(fn ($item) => (string) ($item->tax_rate ?? 0))->map(fn ($group) => (float) $group->sum('tax_total'))->filter(fn ($v) => $v > 0);
    $means = collect($data->payments)->pluck('method')->unique()->implode(' · ');
    $stateClass = ($f['status'] ?? '') === 'accepted' ? 'ok' : (in_array($f['status'] ?? '', ['rejected', 'error'], true) ? 'bad' : 'warn');
    $activity = (string) ($f['economic_activity'] ?? '');
    if (str_ends_with($activity, '.0')) {
        $activity = substr($activity, 0, -2);
    }
    $mvsLogo = ($pdfMode ?? false) ? 'file://'.public_path('images/logo-mvs.png') : asset('images/logo-mvs.png');
    $coLogo = ! empty($company->logo) ? (($pdfMode ?? false) ? 'file://'.public_path('storage/'.$company->logo) : asset('storage/'.$company->logo)) : null;
@endphp
<main class="page">
    <div class="brand-col">
        @if($coLogo)<img class="brand-logo" src="{{ $coLogo }}" alt="{{ $co['trade_name'] }}">@endif
        <div class="company-name">{{ $co['trade_name'] }}</div>
        <div class="company-legal">
            {{ $co['legal_name'] }}<br>
            {{ $co['identification_number'] }}<br>
            {{ $br['name'] }}@if($br['phone']) · Tel. {{ $br['phone'] }}@endif<br>
            {{ $br['address'] ?? $co['address'] }}
        </div>
    </div>
    <div class="doc-col">
        <p class="doc-title">FACTURA <span class="gold">ELECTRÓNICA</span></p>
        <table class="doc-table">
            <tr>
                <td class="lbl">Consecutivo:</td>
                <td><span class="mono">{{ $f['consecutivo'] ?: '—' }}</span></td>
            </tr>
            <tr>
                <td class="lbl">Clave electrónica:</td>
                <td><span class="mono">{{ $f['clave'] }}</span></td>
            </tr>
            <tr>
                <td class="lbl">Fecha / hora:</td>
                <td>{{ $doc['completed_at'] }}</td>
            </tr>
            <tr>
                <td class="lbl">Condición de venta:</td>
                <td>{{ $f['condition'] }}</td>
            </tr>
            <tr>
                <td class="lbl">Medio de pago:</td>
                <td>{{ $means !== '' ? $means : '—' }}</td>
            </tr>
            <tr>
                <td class="lbl">Moneda:</td>
                <td>CRC — Colones</td>
            </tr>
            <tr>
                <td colspan="2" style="padding-top:1.2mm"><span class="badge {{ $stateClass }}">Estado: {{ $f['status_label'] }}</span></td>
            </tr>
        </table>
    </div>
    <div class="clr"></div>

    <div class="section">
        <div class="section-label">Facturar a</div>
        <table class="bill-table">
            <tr>
                <td><b>Razón social</b>{{ $cust['name'] }}</td>
                <td><b>Identificación</b>{{ $cust['identification'] ?: '—' }}</td>
                <td><b>Correo</b>{{ $cust['email'] ?: '—' }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-label">Productos</div>
        <table class="items">
            <thead>
                <tr>
                    <th>Artículo</th>
                    <th style="width:14mm">Cantidad</th>
                    <th style="width:24mm">Precio</th>
                    <th style="width:24mm">Impuesto</th>
                    <th style="width:28mm">Total</th>
                </tr>
            </thead>
            <tbody>
            @foreach($sale->items as $it)
                <tr>
                    <td>
                        <span class="item-name">{{ $it->product?->name ?? $it->description }}</span>
                        @if($it->cabys_code ?? null)
                            <span class="item-cabys">CABYS {{ $it->cabys_code }}@if($it->product_code) · Código {{ $it->product_code }}@endif</span>
                        @elseif($it->product_code)
                            <span class="item-cabys">Código {{ $it->product_code }}</span>
                        @endif
                    </td>
                    <td>{{ rtrim(rtrim(number_format((float) $it->quantity, 4, ',', '.'), '0'), ',') }}</td>
                    <td>₡{{ $money($it->unit_price) }}</td>
                    <td>₡{{ $money($it->tax_total) }}@if(($it->tax_rate ?? 0) > 0)<br><span style="font-size:7.5px;color:#64748b">{{ (int) $it->tax_rate }}%</span>@endif</td>
                    <td>₡{{ $money($it->total) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="right-col">
            <table class="totals">
                <tr class="muted"><td>Subtotal</td><td>₡{{ $money($subtotal) }}</td></tr>
                @if($discount > 0)
                    <tr class="muted"><td>Descuentos</td><td>-₡{{ $money($discount) }}</td></tr>
                @endif
                <tr class="strong"><td>Total gravado</td><td>₡{{ $money($subtotal - $discount) }}</td></tr>
                @foreach($taxGroups as $rate => $amount)
                    <tr class="muted"><td>IVA {{ rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.') }}%</td><td>₡{{ $money($amount) }}</td></tr>
                @endforeach
                @if($rounding != 0.0)
                    <tr class="muted"><td>Redondeo</td><td>₡{{ $money($rounding) }}</td></tr>
                @endif
                <tr class="grand"><td>TOTAL</td><td class="amount">₡{{ $money($total) }}</td></tr>
            </table>
            <p class="words">{{ \App\Support\NumberToWordsEs::convert($total) }}</p>
        </div>
        <div class="left-col">
            @if($data->loyalty)
                <div class="loyalty">
                    <strong>Fidelización:</strong>
                    @if($data->loyalty['kind'] === 'history')
                        saldo anterior ₡{{ $money($data->loyalty['balance_before']) }} ·
                    @endif
                    {{ $data->loyalty['kind'] === 'history' ? 'saldo final' : 'saldo actual' }} <strong>₡{{ $money($data->loyalty['balance_after']) }}</strong>
                    @if((float) ($data->loyalty['earned'] ?? 0) > 0) · +{{ number_format((float) $data->loyalty['earned'], 2, ',', '.') }} pts ganados @endif
                    @if($data->loyalty['adjusted']) · (saldo ajustado) @endif
                </div>
            @endif
            @if(count($data->credit_note_applications) > 0)
                <div class="loyalty" style="margin-top:2mm">
                    <strong>Notas de crédito:</strong>
                    @foreach($data->credit_note_applications as $cn)
                        {{ $cn['credit_note_number'] }} ₡{{ $cn['amount'] }}@if(!$loop->last) · @endif
                    @endforeach
                </div>
            @endif
        </div>
        <div class="clr"></div>
    </div>

    <div class="section">
        <div class="section-label">Emisor y contacto</div>
        <table class="issuer-table">
            <tr>
                <td><b>Razón social</b>{{ $co['legal_name'] ?: $co['trade_name'] }}</td>
                <td><b>Identificación</b>{{ $co['identification_number'] }}</td>
                <td><b>Actividad económica</b>{{ $activity !== '' ? $activity : '—' }}</td>
                <td><b>Correo / teléfono</b>{{ $co['email'] ?: '—' }} · {{ $co['phone'] ?: ($br['phone'] ?: '—') }}</td>
            </tr>
            <tr>
                <td colspan="4"><b>Dirección</b>{{ $br['address'] ?? $co['address'] }} — Sucursal/Terminal {{ $f['branch_code'] }} / {{ $f['terminal_code'] }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="legal">
            <p>Representación impresa de un comprobante electrónico autorizado por el Ministerio de Hacienda de Costa Rica.</p>
            <p>Versión del documento electrónico 4.4. Resolución MH-DGT-RES-0027-2024 de fecha 13 de noviembre de 2024, publicada en La Gaceta del 19 de noviembre de 2024.</p>
            <p>Verifique la autenticidad de este comprobante en el Portal de Hacienda utilizando la clave electrónica.</p>
        </div>
        <div class="qr-block">
            @if($f['qr_image'])
                <img src="{{ $f['qr_image'] }}" alt="QR del comprobante electrónico">
            @endif
            <span class="qr-cap">Comprobante electrónico</span>
        </div>
        <div class="clr"></div>
    </div>

    <div class="brand-footer">
        <span>Generado por</span>
        <img src="{{ $mvsLogo }}" alt="MVS Commerce">
        <span>MVS Commerce — plataforma comercial</span>
    </div>

    @unless($pdfMode ?? false)
        <div class="actions">
            <button type="button" onclick="window.print()">Imprimir</button>
            <a href="{{ route('pos.receipt.pdf', $sale) }}?format=letter">Descargar PDF</a>
            <a href="{{ auth()->user()->hasPermission('ventas.ver', $company) ? route('ventas.show', $sale) : route('pos.index') }}">Volver</a>
        </div>
    @endunless
</main>
</body>
</html>
