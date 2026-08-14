<!doctype html>
<html lang="es"><body style="margin:0;background:#f1f5f9;font-family:Arial,sans-serif;color:#0f172a">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:24px 8px"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-collapse:collapse">
<tr><td style="background:#000000;color:#ffffff;padding:24px"><div style="font-size:12px;color:#f59e0b;text-transform:uppercase">MVS Commerce</div><h1 style="margin:6px 0 0;font-size:24px">Cierre de Caja</h1><p style="margin:8px 0 0;color:#e2e8f0">{{ $session->session_number }} — {{ $session->cashRegister->name }}</p></td></tr>
<tr><td style="padding:24px">
<h2 style="font-size:17px;margin:0 0 10px">Sesión</h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="7" style="border-collapse:collapse">
@foreach([
    'Empresa' => $session->company->trade_name,
    'Sucursal' => $session->branch->name,
    'Abierta por' => $session->openedBy->name,
    'Cerrada por' => $session->closedBy->name,
    'Apertura' => $session->opened_at->copy()->timezone($timezone)->format('d/m/Y H:i:s'),
    'Cierre' => $session->closed_at->copy()->timezone($timezone)->format('d/m/Y H:i:s'),
    'Duración' => intdiv($durationMinutes,60).' h '.($durationMinutes%60).' min',
] as $label => $value)
<tr><td style="border-bottom:1px solid #e2e8f0;color:#64748b">{{ $label }}</td><td align="right" style="border-bottom:1px solid #e2e8f0"><strong>{{ $value }}</strong></td></tr>
@endforeach
</table>

<h2 style="font-size:17px;margin:24px 0 10px">Resumen CRC</h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;background:#fffbeb">
@foreach(['Fondo inicial'=>$session->opening_amount,'Esperado'=>$session->expected_cash,'Reportado'=>$session->counted_cash,'Diferencia'=>$session->difference_amount] as $label=>$amount)
<tr><td style="border-bottom:1px solid #fde68a">{{ $label }}</td><td align="right" style="border-bottom:1px solid #fde68a"><strong>₡{{ number_format((float)$amount,0,',','.') }}</strong></td></tr>
@endforeach
</table>

<h2 style="font-size:17px;margin:24px 0 10px">Ventas y pagos válidos</h2>
<p style="margin:0 0 8px">Ventas completadas: <strong>{{ (int)$sales->quantity }}</strong> — <strong>₡{{ number_format((float)$sales->total,0,',','.') }}</strong></p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="7" style="border-collapse:collapse">
@forelse($payments as $payment)<tr><td style="border-bottom:1px solid #e2e8f0">{{ $payment->name }} ({{ $payment->code }})</td><td align="right" style="border-bottom:1px solid #e2e8f0">₡{{ number_format((float)$payment->amount,0,',','.') }}</td></tr>@empty<tr><td style="color:#64748b">Sin pagos.</td></tr>@endforelse
</table>

<h2 style="font-size:17px;margin:24px 0 10px">Movimientos manuales</h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="7" style="border-collapse:collapse">
@forelse($movements as $movement)<tr><td style="border-bottom:1px solid #e2e8f0">{{ match($movement->type){'entry'=>'Entrada','exit'=>'Salida','withdrawal'=>'Retiro',default=>ucfirst($movement->type)} }}</td><td align="right" style="border-bottom:1px solid #e2e8f0">{{ $movement->direction === 'out' ? '−' : '+' }}₡{{ number_format((float)$movement->amount,0,',','.') }}</td></tr>@empty<tr><td style="color:#64748b">Sin movimientos manuales.</td></tr>@endforelse
</table>

<h2 style="font-size:17px;margin:24px 0 10px">Conteo por denominación</h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="7" style="border-collapse:collapse">
@foreach($session->countDetails as $detail)<tr><td style="border-bottom:1px solid #e2e8f0">{{ $detail->quantity }} × ₡{{ number_format((float)$detail->denomination_value,0,',','.') }}</td><td align="right" style="border-bottom:1px solid #e2e8f0">₡{{ number_format((float)$detail->total_amount,0,',','.') }}</td></tr>@endforeach
</table>

<h2 style="font-size:17px;margin:24px 0 10px">Conciliación por forma de pago</h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="7" style="border-collapse:collapse">
<tr style="background:#f8fafc"><th align="left">Método</th><th align="right">Esperado</th><th align="right">Reportado</th><th align="right">Diferencia</th></tr>
@forelse($session->paymentReconciliations as $row)<tr><td style="border-bottom:1px solid #e2e8f0">{{ $row->payment_method_name_snapshot }} ({{ $row->payment_method_code_snapshot }})</td><td align="right" style="border-bottom:1px solid #e2e8f0">₡{{ number_format((float)$row->expected_amount,0,',','.') }}</td><td align="right" style="border-bottom:1px solid #e2e8f0">₡{{ number_format((float)$row->reported_amount,0,',','.') }}</td><td align="right" style="border-bottom:1px solid #e2e8f0">₡{{ number_format((float)$row->difference_amount,0,',','.') }}</td></tr>@empty<tr><td colspan="4" style="color:#64748b">Sin conciliaciones.</td></tr>@endforelse
</table>

@if($session->differenceAuthorizedBy)<p style="margin:20px 0 0;padding:12px;background:#fef3c7"><strong>Diferencia autorizada por:</strong> {{ $session->differenceAuthorizedBy->name }} — {{ $session->difference_authorized_at->copy()->timezone($timezone)->format('d/m/Y H:i:s') }}</p>@endif
@if(filled($session->closing_notes))<div style="margin-top:20px"><strong>Notas del cierre</strong><p style="white-space:pre-wrap">{{ $session->closing_notes }}</p></div>@endif
@if($session->accepts_usd_snapshot)<div style="margin-top:20px;padding:12px;border:1px solid #e2e8f0"><strong>USD</strong><p>Fondo inicial: ${{ number_format((float)$session->opening_amount_usd,2,'.',',') }} · Tipo de cambio: ₡{{ number_format((float)$session->usd_exchange_rate,2,',','.') }}</p>@if($session->counted_cash_usd !== null)<p>Esperado: ${{ number_format((float)$session->expected_cash_usd,2,'.',',') }} · Reportado: ${{ number_format((float)$session->counted_cash_usd,2,'.',',') }} · Diferencia: ${{ number_format((float)$session->difference_amount_usd,2,'.',',') }}</p>@endif</div>@endif
</td></tr><tr><td style="background:#f59e0b;height:6px;font-size:0">&nbsp;</td></tr>
</table></td></tr></table></body></html>
