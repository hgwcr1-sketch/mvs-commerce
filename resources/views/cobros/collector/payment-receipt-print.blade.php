{{-- Recibo formal print-friendly: vista independiente (sin navbar, menús ni
     footer del sistema). La impresión sale SOLO con este recibo. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recibo {{ $receipt->receipt_number }} — {{ $company->trade_name }}</title>
    <style>
        @page { margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #0f172a;
               font-family: ui-sans-serif, system-ui, -apple-system, Arial, sans-serif; }
        .receipt { max-width: 640px; margin: 0 auto; padding: 24px 16px; }
        .brand { display: flex; align-items: center; gap: 12px;
                 border-bottom: 3px solid #D4AF37; padding-bottom: 12px; }
        .brand img { width: 48px; height: 48px; }
        .brand strong { display: block; font-size: 18px; }
        .brand span { color: #475569; font-size: 14px; }
        .title { margin: 20px 0 0; font-size: 14px; letter-spacing: .08em;
                 text-transform: uppercase; color: #475569; }
        .number { margin: 4px 0 0; font-size: 24px; font-weight: 800; word-break: break-all; }
        .date { margin: 4px 0 0; color: #475569; font-size: 14px; }
        dl { margin: 20px 0 0; }
        .row { display: flex; justify-content: space-between; gap: 16px;
               padding: 8px 0; border-bottom: 1px solid #e2e8f0; }
        .row dt { margin: 0; color: #475569; font-size: 14px; }
        .row dd { margin: 0; text-align: right; font-weight: 600; font-size: 15px; word-break: break-word; }
        .row.total dd { font-size: 20px; font-weight: 800; }
        .notice { margin-top: 16px; padding: 10px 12px; border-radius: 8px; font-size: 14px; }
        .notice.annul { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .actions { margin-top: 20px; }
        .btn { display: inline-flex; align-items: center; justify-content: center;
               min-height: 52px; padding: 12px 20px; border-radius: 12px; border: 1px solid #cbd5e1;
               background: #fff; color: #0f172a; font-size: 16px; font-weight: 700; cursor: pointer; }
        .footnote { margin-top: 16px; color: #64748b; font-size: 12px; }
        @media print { .actions { display: none; } .receipt { padding: 0; } }
    </style>
</head>
<body>
<div class="receipt">
    <div class="brand">
        <img src="{{ asset('images/logo-mvs-cobros.png') }}" alt="MVS CobrOS" width="48" height="48">
        <div>
            <strong>MVS CobrOS</strong>
            <span>{{ $company->trade_name }}</span>
        </div>
    </div>

    <p class="title">Recibo de cobro</p>
    <p class="number">{{ $receipt->receipt_number }}</p>
    <p class="date">{{ $receipt->paid_at->timezone($company->timezone)->format('d/m/Y H:i') }}</p>

    <dl>
        <div class="row"><dt>Cliente</dt><dd>{{ $receipt->customer->name }}</dd></div>
        <div class="row"><dt>Cobrador</dt><dd>{{ $receipt->collector->name }}</dd></div>
        <div class="row"><dt>Forma de pago</dt><dd>{{ $receipt->paymentMethod->name }}</dd></div>
        @if($receipt->reference)
            <div class="row"><dt>Referencia</dt><dd>{{ $receipt->reference }}</dd></div>
        @endif
        @if($receipt->balance_before !== null)
            <div class="row"><dt>Saldo anterior</dt><dd>@include('cobros.components.money', ['amount' => $receipt->balance_before, 'currency' => $receipt->currency_code])</dd></div>
        @endif
        <div class="row total"><dt>Monto abonado</dt><dd>@include('cobros.components.money', ['amount' => $receipt->amount, 'currency' => $receipt->currency_code])</dd></div>
        @if($receipt->balance_after !== null)
            <div class="row total"><dt>Saldo nuevo</dt><dd>@include('cobros.components.money', ['amount' => $receipt->balance_after, 'currency' => $receipt->currency_code])</dd></div>
        @endif
        @if(!empty($loanApplication))
            <div class="row"><dt>Préstamo</dt><dd>{{ $loanApplication['reference'] }}</dd></div>
            <div class="row"><dt>Aplicado al préstamo</dt><dd>@include('cobros.components.money', ['amount' => $loanApplication['amount'], 'currency' => $receipt->currency_code])</dd></div>
            @if(bccomp((string) $loanApplication['mora'], '0', 4) > 0)
                <div class="row"><dt>Mora pagada</dt><dd>@include('cobros.components.money', ['amount' => $loanApplication['mora'], 'currency' => $receipt->currency_code])</dd></div>
            @endif
            @if($loanApplication['cancelled'])
                <div class="row"><dt>Estado</dt><dd>Préstamo cancelado ✓</dd></div>
            @else
                <div class="row"><dt>Próximo pago</dt><dd>{{ $loanApplication['next_due'] }}</dd></div>
                <div class="row"><dt>Monto pendiente</dt><dd>@include('cobros.components.money', ['amount' => $loanApplication['next_amount'], 'currency' => $receipt->currency_code])</dd></div>
            @endif
        @endif
        @if($receipt->paymentMethod->type === \App\Models\PaymentMethod::TYPE_CASH && $receipt->tendered_amount !== null)
            <div class="row"><dt>Paga con</dt><dd>@include('cobros.components.money', ['amount' => $receipt->tendered_amount, 'currency' => $receipt->currency_code])</dd></div>
            <div class="row"><dt>Vuelto</dt><dd>@include('cobros.components.money', ['amount' => $receipt->change_amount, 'currency' => $receipt->currency_code])</dd></div>
        @endif
        @if($receipt->balance_after !== null && bccomp((string) $receipt->balance_after, '0', 4) > 0 && $receipt->accountReceivable->due_date)
            <div class="row"><dt>Próxima fecha de vencimiento</dt><dd>{{ $receipt->accountReceivable->due_date->format('d/m/Y') }}</dd></div>
        @endif
    </dl>

    @if($receipt->annulled_at)
        <div class="notice annul">
            <strong>Cobro anulado</strong>
            <div>Motivo: {{ $receipt->annul_reason }}</div>
            <div>{{ $receipt->annulledBy?->name }} · {{ $receipt->annulled_at->timezone($company->timezone)->format('d/m/Y H:i') }}</div>
            @if($reversal)
                <div>Reverso: {{ $reversal->reversal_number }}</div>
            @endif
        </div>
    @endif

    <p class="footnote">Este cobro no se ingresó a una sesión de caja: queda en custodia hasta la liquidación.</p>

    <div class="actions">
        <button type="button" class="btn" onclick="window.print()">🖨️ IMPRIMIR</button>
    </div>
</div>
<script>
    window.addEventListener('load', function () { window.print(); });
</script>
</body>
</html>
