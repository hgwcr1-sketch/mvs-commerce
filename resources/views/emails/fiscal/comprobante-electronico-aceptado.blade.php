<!doctype html>
<html lang="es"><body style="margin:0;background:#f1f5f9;font-family:Arial,sans-serif;color:#0f172a">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:24px 8px"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-collapse:collapse">
<tr><td style="background:#000000;color:#ffffff;padding:24px"><div style="font-size:12px;color:#D4AF37;text-transform:uppercase">MVS Commerce</div><h1 style="margin:6px 0 0;font-size:24px">Comprobante electrónico</h1><p style="margin:8px 0 0;color:#e2e8f0">{{ $typeLabel }} — {{ $company->trade_name }}</p></td></tr>
<tr><td style="padding:24px">
<p style="margin:0 0 16px">Su comprobante electrónico fue procesado y quedó en estado <strong>Aceptado por Hacienda</strong>. Los archivos disponibles se encuentran adjuntos a este correo.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="8" style="border:1px solid #D4AF37;border-collapse:collapse;background:#fffbeb">
<tr><td colspan="2" style="background:#D4AF37;color:#111827;font-size:16px;font-weight:bold">COMPROBANTE</td></tr>
<tr><td style="border-bottom:1px solid #fde68a;color:#64748b">Empresa</td><td align="right" style="border-bottom:1px solid #fde68a"><strong>{{ $company->legal_name ?: $company->trade_name }}</strong></td></tr>
<tr><td style="border-bottom:1px solid #fde68a;color:#64748b">Tipo</td><td align="right" style="border-bottom:1px solid #fde68a"><strong>{{ $typeLabel }}</strong></td></tr>
<tr><td style="border-bottom:1px solid #fde68a;color:#64748b">Consecutivo</td><td align="right" style="border-bottom:1px solid #fde68a"><strong>{{ $reference }}</strong></td></tr>
@isset($clave)
@if($clave)<tr><td style="border-bottom:1px solid #fde68a;color:#64748b">Clave</td><td align="right" style="border-bottom:1px solid #fde68a"><strong>{{ $clave }}</strong></td></tr>@endif
@endisset
@if($fecha)<tr><td style="border-bottom:1px solid #fde68a;color:#64748b">Fecha</td><td align="right" style="border-bottom:1px solid #fde68a"><strong>{{ $fecha }}</strong></td></tr>@endif
@if($total !== null)<tr><td style="border-bottom:1px solid #fde68a;color:#64748b">Total</td><td align="right" style="border-bottom:1px solid #fde68a"><strong>₡{{ $total }}</strong></td></tr>@endif
<tr><td style="color:#64748b">Estado</td><td align="right"><strong style="color:#15803d">Aceptado</strong></td></tr>
</table>

<h2 style="font-size:17px;margin:24px 0 10px">Archivos adjuntos</h2>
@forelse($adjuntos as $adjunto)
<p style="margin:0 0 6px;color:#334155">• {{ $adjunto }}</p>
@empty
<p style="margin:0 0 6px;color:#64748b">Sin archivos disponibles para este comprobante.</p>
@endforelse
<p style="margin:16px 0 0;color:#64748b;font-size:13px">Guarde estos archivos para sus registros. Si necesita una copia adicional, solicítela al establecimiento.</p>
</td></tr>
<tr><td style="background:#0f172a;color:#94a3b8;padding:16px;text-align:center;font-size:12px">Procesado mediante MVS Commerce</td></tr>
</table>
</td></tr></table>
</body></html>
