Su comprobante electrónico fue procesado y quedó en estado Aceptado por Hacienda.

Empresa: {{ $company->legal_name ?: $company->trade_name }}
Tipo: {{ $typeLabel }}
Consecutivo: {{ $reference }}
@isset($clave)
@if($clave)Clave: {{ $clave }}
@endif
@endisset
@if($fecha)Fecha: {{ $fecha }}
@endif
@if($total !== null)Total: ₡{{ $total }}
@endif
Estado: Aceptado

Archivos adjuntos:
@if(count($adjuntos))
@foreach($adjuntos as $adjunto)- {{ $adjunto }}
@endforeach
@else
Sin archivos disponibles para este comprobante.
@endif

Procesado mediante MVS Commerce
