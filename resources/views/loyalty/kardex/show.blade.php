@extends('layouts.app')

@section('title', 'Detalle de movimiento de Fidelidad')

@section('content')
@php
    $points = static function ($value, bool $signed = false): string {
        $number = (float) $value;
        $formatted = rtrim(rtrim(number_format(abs($number), 4, ',', '.'), '0'), ',');
        return ($signed ? ($number >= 0 ? '+' : '-') : '').$formatted.' puntos';
    };
    $money = static fn ($value): string => '₡'.number_format((float) $value, 2, ',', '.');
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><h1 class="text-2xl font-semibold text-slate-800">Movimiento #{{ $movement->id }}</h1><p class="mt-1 text-sm text-slate-500">Detalle auditable del movimiento de fidelidad.</p></div>
        <a href="{{ route('loyalty.kardex.index') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 font-semibold text-slate-700 hover:bg-slate-50">Volver al Kardex</a>
    </div>

    <x-card>
        <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Cliente</dt><dd class="mt-1 font-semibold text-slate-800">{{ $movement->customer?->name ?? 'No disponible' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Empresa</dt><dd class="mt-1 text-slate-800">{{ $movement->company?->trade_name }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Sucursal</dt><dd class="mt-1 text-slate-800">{{ $movement->branch?->name ?? 'Sin sucursal' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Fecha efectiva</dt><dd class="mt-1 text-slate-800">{{ $movement->effective_at->format('d/m/Y H:i') }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Tipo</dt><dd class="mt-1 text-slate-800">{{ $types[$movement->type] ?? $movement->type }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Puntos</dt><dd class="mt-1 font-bold {{ (float) $movement->points >= 0 ? 'text-emerald-700' : 'text-red-700' }}">{{ $points($movement->points, true) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Saldo anterior</dt><dd class="mt-1 text-slate-800">{{ $points($movement->balance_before) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Saldo posterior</dt><dd class="mt-1 font-semibold text-slate-800">{{ $points($movement->balance_after) }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase text-slate-500">Concepto</dt><dd class="mt-1 text-slate-800">{{ $movement->description }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Usuario</dt><dd class="mt-1 text-slate-800">{{ $movement->user?->name ?? 'Sistema' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Saldo actual de la cuenta</dt><dd class="mt-1 font-semibold text-slate-800">{{ $points($movement->loyaltyAccount->balance) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Origen</dt><dd class="mt-1 text-slate-800">{{ $movement->source_type ? class_basename($movement->source_type) : 'Sin origen' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Referencia</dt><dd class="mt-1 text-slate-800">{{ $movement->source_id ? '#'.$movement->source_id : '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Clave de evento</dt><dd class="mt-1 break-all text-slate-800">{{ $movement->event_key ?: '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Movimiento relacionado</dt><dd class="mt-1 text-slate-800">@if($movement->relatedMovement)<a class="font-semibold text-amber-700 hover:underline" href="{{ route('loyalty.kardex.show', $movement->relatedMovement) }}">#{{ $movement->relatedMovement->id }} · {{ $types[$movement->relatedMovement->type] ?? $movement->relatedMovement->type }}</a>@else — @endif</dd></div>
            @if($movement->base_amount !== null)<div><dt class="text-xs font-semibold uppercase text-slate-500">Importe base</dt><dd class="mt-1 text-slate-800">{{ $money($movement->base_amount) }}</dd></div>@endif
            @if($movement->earning_percentage !== null)<div><dt class="text-xs font-semibold uppercase text-slate-500">Porcentaje</dt><dd class="mt-1 text-slate-800">{{ rtrim(rtrim(number_format((float) $movement->earning_percentage, 4, ',', '.'), '0'), ',') }}%</dd></div>@endif
            @if($movement->point_value !== null)<div><dt class="text-xs font-semibold uppercase text-slate-500">Valor por punto</dt><dd class="mt-1 text-slate-800">{{ $money($movement->point_value) }}</dd></div>@endif
        </dl>
    </x-card>

    @if(!empty($movement->metadata))
        <x-card>
            <x-slot:header><h2 class="text-lg font-semibold text-slate-800">Información adicional</h2></x-slot:header>
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach($movement->metadata as $key => $value)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"><dt class="text-xs font-semibold uppercase text-slate-500">{{ str($key)->replace('_', ' ')->title() }}</dt><dd class="mt-1 break-words text-sm text-slate-800">{{ is_scalar($value) || $value === null ? ($value ?? '—') : collect($value)->map(fn ($item, $itemKey) => is_string($itemKey) ? $itemKey.': '.$item : $item)->implode(', ') }}</dd></div>
                @endforeach
            </dl>
        </x-card>
    @endif
</div>
@endsection
