@props(['online' => true])

@php
    $badge = $online ? 'conexión estable' : 'sin conexión';
    $clz   = $online ? 'border-green-300 bg-green-50 text-green-800' : 'border-amber-300 bg-amber-50 text-amber-800';
@endphp

@if(!$online)
    <div class="cobros-connection" role="status">
        <div class="flex items-center gap-2 text-sm">
            <span class="inline-flex h-2 w-2 rounded-full bg-amber-500" aria-hidden="true"></span>
            <span class="font-semibold">Sin conexión</span>
            <span class="text-xs text-amber-600">{{ $badge }}</span>
        </div>
        <p class="mt-1 text-xs text-amber-700">
            Las capturas que realices se guardarán como borradores idempotente;
            los cobros se aplican en línea mediante la confirmación del servidor.
        </p>
    </div>
@endif
