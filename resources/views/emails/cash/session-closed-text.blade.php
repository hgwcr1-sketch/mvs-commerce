MVS Commerce — Cierre de Caja

Sesión: {{ $session->session_number }}
Empresa: {{ $session->company->trade_name }}
Sucursal: {{ $session->branch->name }}
Caja: {{ $session->cashRegister->name }}
Abierta por: {{ $session->openedBy->name }}
Cerrada por: {{ $session->closedBy->name }}
Apertura: {{ $session->opened_at->copy()->timezone($timezone)->format('d/m/Y H:i:s') }}
Cierre: {{ $session->closed_at->copy()->timezone($timezone)->format('d/m/Y H:i:s') }}
Duración: {{ intdiv($durationMinutes,60) }} h {{ $durationMinutes%60 }} min
Total documentos: {{ $session->documents_count }}

RESUMEN CRC
Fondo inicial: ₡{{ number_format((float)$session->opening_amount,0,',','.') }}
Esperado: ₡{{ number_format((float)$session->expected_cash,0,',','.') }}
Reportado: ₡{{ number_format((float)$session->counted_cash,0,',','.') }}
Diferencia: ₡{{ number_format((float)$session->difference_amount,0,',','.') }}

TOTAL DE CONCILIACIÓN
@foreach(['expected'=>'Total esperado','reported'=>'Total declarado','difference'=>'Diferencia general'] as $key=>$label)
{{ $label }}: ₡{{ number_format($closingSummary[$key],2,',','.') }}
@endforeach
Efectivo físico más los demás medios, sin duplicar el efectivo por cobros. Revise cada diferencia aunque el neto sea cero.

DESGLOSE DE DOCUMENTOS
Ventas completadas: {{ $closingSummary['completed_sales_count'] }}
Abonos Cuentas por Cobrar: {{ $closingSummary['account_receivable_payments_count'] }}
Abonos Apartados: {{ $closingSummary['layaway_payments_count'] }}
Pagos Cuentas por Pagar: {{ $closingSummary['account_payable_payments_count'] }}
Total documentos: {{ $closingSummary['documents_count'] }}

VENTAS Y PAGOS VÁLIDOS
Ventas completadas: {{ $closingSummary['completed_sales_count'] }} — ₡{{ number_format((float)$sales->total,0,',','.') }}
@forelse($payments as $payment)
{{ $payment->name }} ({{ $payment->code }}): ₡{{ number_format((float)$payment->amount,0,',','.') }}
@empty
Sin pagos.
@endforelse

MOVIMIENTOS MANUALES
@forelse($movements as $movement)
{{ match($movement->type){'entry'=>'Entrada','exit'=>'Salida','withdrawal'=>'Retiro',default=>ucfirst($movement->type)} }}: {{ $movement->direction === 'out' ? '−' : '+' }}₡{{ number_format((float)$movement->amount,0,',','.') }}
@empty
Sin movimientos manuales.
@endforelse

CONTEO POR DENOMINACIÓN
@foreach($session->countDetails as $detail)
{{ $detail->quantity }} × ₡{{ number_format((float)$detail->denomination_value,0,',','.') }} = ₡{{ number_format((float)$detail->total_amount,0,',','.') }}
@endforeach

CONCILIACIÓN
@forelse($session->paymentReconciliations as $row)
{{ $row->payment_method_name_snapshot }} ({{ $row->payment_method_code_snapshot }}): esperado ₡{{ number_format((float)$row->expected_amount,0,',','.') }}, reportado ₡{{ number_format((float)$row->reported_amount,0,',','.') }}, diferencia ₡{{ number_format((float)$row->difference_amount,0,',','.') }}
@empty
Sin conciliaciones.
@endforelse
@if($session->differenceAuthorizedBy)

Diferencia autorizada por: {{ $session->differenceAuthorizedBy->name }} — {{ $session->difference_authorized_at->copy()->timezone($timezone)->format('d/m/Y H:i:s') }}
@endif
@if(filled($session->closing_notes))

Notas: {{ $session->closing_notes }}
@endif
@if($session->accepts_usd_snapshot)

USD
Fondo inicial: ${{ number_format((float)$session->opening_amount_usd,2,'.',',') }}
Tipo de cambio: ₡{{ number_format((float)$session->usd_exchange_rate,2,',','.') }}
@if($session->counted_cash_usd !== null)
Esperado: ${{ number_format((float)$session->expected_cash_usd,2,'.',',') }}
Reportado: ${{ number_format((float)$session->counted_cash_usd,2,'.',',') }}
Diferencia: ${{ number_format((float)$session->difference_amount_usd,2,'.',',') }}
@endif
@endif
