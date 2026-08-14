<!doctype html>
<html lang="es"><body style="margin:0;background:#f1f5f9;font-family:Arial,sans-serif;color:#0f172a">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:24px 8px"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-collapse:collapse">
<tr><td style="background:#000000;color:#ffffff;padding:24px"><div style="font-size:12px;color:#f59e0b;text-transform:uppercase">MVS Commerce</div><h1 style="margin:6px 0 0;font-size:24px">Apertura de Caja</h1></td></tr>
<tr><td style="padding:24px">
<p style="margin:0 0 20px">Se abrió la sesión <strong>{{ $session->session_number }}</strong>.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse">
@foreach([
    'Empresa' => $session->company->trade_name,
    'Sucursal' => $session->branch->name,
    'Caja' => $session->cashRegister->name,
    'Cajero' => $session->openedBy->name,
    'Fecha y hora' => $openedAt->format('d/m/Y H:i:s').' ('.$timezone.')',
    'Fondo inicial CRC' => '₡'.number_format((float)$session->opening_amount,0,',','.'),
    'Moneda' => $session->currency_code,
    'Modo' => $session->company->cashSetting?->session_mode === 'shared' ? 'Compartido' : 'Individual',
] as $label => $value)
<tr><td style="border-bottom:1px solid #e2e8f0;color:#64748b">{{ $label }}</td><td align="right" style="border-bottom:1px solid #e2e8f0"><strong>{{ $value }}</strong></td></tr>
@endforeach
@if($session->accepts_usd_snapshot)
<tr><td style="border-bottom:1px solid #e2e8f0;color:#64748b">Fondo inicial USD</td><td align="right" style="border-bottom:1px solid #e2e8f0"><strong>${{ number_format((float)$session->opening_amount_usd,2,'.',',') }}</strong></td></tr>
<tr><td style="border-bottom:1px solid #e2e8f0;color:#64748b">Tipo de cambio</td><td align="right" style="border-bottom:1px solid #e2e8f0"><strong>₡{{ number_format((float)$session->usd_exchange_rate,2,',','.') }}</strong></td></tr>
@endif
</table>
</td></tr><tr><td style="background:#f59e0b;height:6px;font-size:0">&nbsp;</td></tr>
</table></td></tr></table></body></html>
