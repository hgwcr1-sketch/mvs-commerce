<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante Caja</title>
    <style>
        *{box-sizing:border-box}html,body{height:auto;margin:0;background:#fff;color:#111827;font-family:Arial,Helvetica,sans-serif;line-height:1.3}
        .receipt{width:58mm;max-width:100%;margin:0 auto;padding:2mm;font-size:11px;height:auto;min-height:0;overflow-wrap:anywhere}
        h1{margin:0 0 2mm;font-size:13px;text-align:center}
        .brand{color:#b7791f;letter-spacing:.05em}
        .center{text-align:center}
        .muted{color:#64748b;font-size:10px}
        .section{margin:4px 0}
        .rule{border-top:1px dashed #cbd5e1;margin:6px 0}
        .field{display:block;font-size:11px;margin:2px 0}
        .field strong{font-weight:700;color:#111827}
        @page{margin:0}
        @media print{.receipt{margin:0}}
    </style>
</head>
<body>
<main class="receipt" data-receipt-format="58mm">
    <h1>{{ $type === 'closing' ? 'CIERRE DE CAJA' : 'APERTURA DE CAJA' }}</h1>
    <div class="section">
        <span class="field"><strong>Empresa:</strong> {{ $company->trade_name }}</span>
        <span class="field"><strong>Sucursal:</strong> {{ $branch->name }}</span>
        <span class="field"><strong>Caja/terminal:</strong> {{ $register->name }}</span>
        <span class="field"><strong>Usuario:</strong> {{ $user_name }}</span>
        <span class="field"><strong>Fecha/Hora:</strong> {{ $date_time }}</span>
    </div>
</main>
<script>window.addEventListener('load',()=>window.print());</script>
</body>
</html>
