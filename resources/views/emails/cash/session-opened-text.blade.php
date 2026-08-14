MVS Commerce — Apertura de Caja

Sesión: {{ $session->session_number }}
Empresa: {{ $session->company->trade_name }}
Sucursal: {{ $session->branch->name }}
Caja: {{ $session->cashRegister->name }}
Cajero: {{ $session->openedBy->name }}
Fecha y hora: {{ $openedAt->format('d/m/Y H:i:s') }} ({{ $timezone }})
Fondo inicial CRC: ₡{{ number_format((float)$session->opening_amount,0,',','.') }}
Moneda: {{ $session->currency_code }}
Modo: {{ $session->company->cashSetting?->session_mode === 'shared' ? 'Compartido' : 'Individual' }}
@if($session->accepts_usd_snapshot)
Fondo inicial USD: ${{ number_format((float)$session->opening_amount_usd,2,'.',',') }}
Tipo de cambio: ₡{{ number_format((float)$session->usd_exchange_rate,2,',','.') }}
@endif
