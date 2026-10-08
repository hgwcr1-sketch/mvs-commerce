<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>MVS CobrOS — Comprobante de préstamo y aceptación</title>
<style>
    * { box-sizing: border-box; }
    @page { margin: 10mm 12mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11.5px; color: #0f172a; margin: 0; line-height: 1.3; }
    .head { border-bottom: 3px solid #D4AF37; padding-bottom: 5px; margin-bottom: 6px; }
    .brand { font-size: 17px; font-weight: bold; letter-spacing: 1px; }
    .gold { color: #8a6d1f; }
    h1 { font-size: 13.5px; margin: 3px 0 0 0; }
    .muted { color: #475569; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; line-height: 1.25; }
    th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
    th { background: #f8fafc; font-size: 10.5px; }
    td.num { text-align: right; }
    .kv { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .kv td { border: none; padding: 3px 0; line-height: 1.25; }
    .kv td.k { width: 46%; color: #475569; }
    .kv td.v { font-weight: bold; text-align: right; }
    .total td { background: #fdf6e3; font-size: 12.5px; }
    .accept { border: 1px solid #D4AF37; background: #fdf6e3; padding: 8px; margin-top: 8px; font-size: 11.5px; page-break-inside: avoid; }
    .sign { margin-top: 8px; page-break-inside: avoid; }
    .sign img { display: block; margin-top: 4px; border-bottom: 1px solid #334155; }
    .small { font-size: 10.5px; color: #475569; margin-top: 3px; }
    .ref { font-size: 10px; color: #94a3b8; text-align: right; margin-top: 6px; }
    .page-break { page-break-before: always; }
    /* Modo compacto automático (7–12 cuotas): solo espaciado, sin bajar el
       texto principal ni cortar información. >12 cuotas puede pasar a página 2. */
    body.compact .kv td { padding: 1.5px 0; }
    body.compact table th, body.compact table td { padding: 2px 5px; }
    body.compact h2 { margin-top: 8px; font-size: 12px; }
    body.compact .accept { padding: 6px; margin-top: 6px; }
    body.compact .sign { margin-top: 5px; }
    body.compact .head { padding-bottom: 4px; margin-bottom: 5px; }
</style>
</head>
<body class="{{ count($rows) >= 7 && count($rows) <= 12 ? 'compact' : '' }}">

<div class="head">
    <div class="brand">MVS CobrOS</div>
    <h1>Comprobante de préstamo y aceptación</h1>
    <div class="small">Préstamo {{ $credit->publicReference() }} · {{ $company->trade_name }}</div>
</div>

<table class="kv">
    <tr><td class="k">Cliente</td><td class="v">{{ $customerName }}</td></tr>
    <tr><td class="k">Identificación</td><td class="v">{{ strtoupper($identificationType) }} {{ $identification }}</td></tr>
    <tr><td class="k">Fecha y hora de entrega</td><td class="v">{{ $acceptance->accepted_at?->format('d/m/Y H:i') }}</td></tr>
    <tr><td class="k">Monto que recibió</td><td class="v">{{ \App\Support\Cobros\Money::human((string) $terms['principal'], $company->currency) }}</td></tr>
    <tr><td class="k">Interés</td><td class="v">{{ $interestLabel }} ({{ \App\Support\Cobros\Money::human($interest, $company->currency) }})</td></tr>
    <tr><td class="k">Cantidad de pagos</td><td class="v">{{ $terms['installment_count'] }}</td></tr>
    <tr><td class="k">Frecuencia</td><td class="v">{{ $frequencyLabel }}</td></tr>
    <tr><td class="k">Monto de los pagos</td><td class="v">{{ \App\Support\Cobros\Money::human($normalAmount, $company->currency) }}@if($normalAmount !== $lastAmount) (último: {{ \App\Support\Cobros\Money::human($lastAmount, $company->currency) }})@endif</td></tr>
    <tr><td class="k">Primer pago</td><td class="v">{{ \Carbon\Carbon::parse((string) $terms['first_due_date'])->format('d/m/Y') }}</td></tr>
    <tr><td class="k">Último pago</td><td class="v">{{ \Carbon\Carbon::parse($lastDueDate)->format('d/m/Y') }}</td></tr>
    <tr><td class="k">Mora</td><td class="v">{{ $lateFeeLabel }}</td></tr>
    <tr><td class="k">Días de gracia</td><td class="v">{{ (int) $terms['late_fee_grace_days'] }} días</td></tr>
    <tr><td class="k">Ruta</td><td class="v">{{ $route?->name ?? '—' }}</td></tr>
    <tr><td class="k">Cobrador</td><td class="v">{{ $collector?->name ?? '—' }}</td></tr>
    <tr class="total"><td class="k"><b>TOTAL QUE PAGARÁ</b></td><td class="v">{{ \App\Support\Cobros\Money::human((string) $terms['total_due'], $company->currency) }}</td></tr>
</table>

<h2 style="font-size:13px; margin-top:16px;">Calendario de pagos</h2>
<table>
    <thead>
        <tr>
            <th style="width:14%">Pago</th>
            <th style="width:34%">Fecha</th>
            <th style="width:26%">Monto</th>
            <th style="width:26%">Saldo restante</th>
        </tr>
    </thead>
    <tbody>
        @php($running = (string) $terms['total_due'])
        @foreach($rows as $row)
            @php($running = bcsub($running, $row['contractual_amount'], 4))
            <tr>
                <td>{{ $row['number'] }}</td>
                <td>{{ \Carbon\Carbon::parse($row['due_date'])->format('d/m/Y') }}</td>
                <td class="num">{{ \App\Support\Cobros\Money::human($row['contractual_amount'], $company->currency) }}</td>
                <td class="num">{{ \App\Support\Cobros\Money::human($running, $company->currency) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="accept">
    <b>ACEPTACIÓN DEL CLIENTE</b>
    <p style="margin: 6px 0 0 0;">{{ $acceptanceText }}</p>
</div>

<div class="sign">
    <b>Firma del cliente</b>
    @if($signature['data'])
        <img src="{{ $signature['data'] }}"
             width="{{ (int) round(min(240, max(1, (int) $signature['width']))) }}"
             height="{{ max(55, (int) round(min(240, max(1, (int) $signature['width'])) * (int) $signature['height'] / max(1, (int) $signature['width']))) }}"
             alt="Firma del cliente">
    @else
        <div style="height:60px; border-bottom:1px solid #334155;"></div>
    @endif
    <div class="small">
        {{ $customerName }} · {{ strtoupper($identificationType) }} {{ $identification }}<br>
        Aceptado el {{ $acceptance->accepted_at?->format('d/m/Y H:i') }} · Referencia del préstamo: {{ $credit->publicReference() }}
    </div>
</div>

<div class="ref">Referencia de aceptación: {{ $acceptance->reference }}</div>

</body>
</html>
