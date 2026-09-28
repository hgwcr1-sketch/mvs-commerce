@extends('layouts.app')

@section('title', 'Kardex de Fidelidad')
@section('description', 'Consulta administrativa de movimientos y saldos de fidelidad.')

@section('content')
@php
    $points = static function ($value, bool $signed = false): string {
        $number = (float) $value;
        $formatted = rtrim(rtrim(number_format(abs($number), 4, ',', '.'), '0'), ',');
        return ($signed ? ($number >= 0 ? '+' : '-') : '').$formatted.' puntos';
    };
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-800">Kardex de Fidelidad</h1>
        <p class="mt-1 text-sm text-slate-500">Historial auditable de puntos de toda la empresa activa.</p>
    </div>

    <x-card>
        <form method="GET" action="{{ route('loyalty.kardex.index') }}" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6">
            <label class="text-sm font-semibold text-slate-700">Cliente
                <select name="customer_id" class="mt-1 h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                    <option value="">Todos los clientes</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(($filters['customer_id'] ?? null) == $customer->id)>{{ $customer->name }}{{ $customer->identification ? ' · '.$customer->identification : '' }}</option>
                    @endforeach
                </select>
            </label>

            <label class="text-sm font-semibold text-slate-700">Desde
                <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
            </label>

            <label class="text-sm font-semibold text-slate-700">Hasta
                <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
            </label>

            <label class="text-sm font-semibold text-slate-700">Sucursal
                <select name="branch_id" class="mt-1 h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                    <option value="">Toda la empresa</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? null) == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="text-sm font-semibold text-slate-700">Tipo
                <select name="type" class="mt-1 h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                    <option value="">Todos los tipos</option>
                    @foreach($types as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['type'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="text-sm font-semibold text-slate-700">Concepto o referencia
                <input type="search" name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" placeholder="Concepto, evento u origen" class="mt-1 h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
            </label>

            <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-6">
                <button type="submit" class="rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-300">Filtrar</button>
                <a href="{{ route('loyalty.kardex.index') }}" class="rounded-lg border border-slate-300 bg-white px-5 py-2.5 font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-300">Limpiar</a>
            </div>
        </form>
    </x-card>

    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-[1180px] w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        @foreach(['Fecha', 'Cliente / saldo actual', 'Tipo', 'Sucursal', 'Concepto', 'Puntos', 'Saldo anterior', 'Saldo posterior', 'Usuario', 'Origen'] as $heading)
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase text-slate-600">{{ $heading }}</th>
                        @endforeach
                        <th class="px-3 py-3"><span class="sr-only">Detalle</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($movements as $movement)
                        <tr class="hover:bg-amber-50/40">
                            <td class="whitespace-nowrap px-3 py-3 text-slate-600">{{ $movement->effective_at->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-3"><div class="font-semibold text-slate-800">{{ $movement->customer?->name ?? 'Cliente no disponible' }}</div><div class="text-xs text-slate-500">Saldo: {{ $points($movement->loyaltyAccount?->balance ?? 0) }}</div></td>
                            <td class="px-3 py-3"><span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ $types[$movement->type] ?? $movement->type }}</span></td>
                            <td class="px-3 py-3 text-slate-600">{{ $movement->branch?->name ?? 'Sin sucursal' }}</td>
                            <td class="max-w-64 px-3 py-3 text-slate-700">{{ $movement->description }}</td>
                            <td class="whitespace-nowrap px-3 py-3 font-bold {{ (float) $movement->points >= 0 ? 'text-emerald-700' : 'text-red-700' }}">{{ $points($movement->points, true) }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-slate-600">{{ $points($movement->balance_before) }}</td>
                            <td class="whitespace-nowrap px-3 py-3 font-semibold text-slate-800">{{ $points($movement->balance_after) }}</td>
                            <td class="px-3 py-3 text-slate-600">{{ $movement->user?->name ?? 'Sistema' }}</td>
                            <td class="px-3 py-3 text-xs text-slate-600">{{ $movement->source_type ? class_basename($movement->source_type) : 'Sin origen' }}{{ $movement->source_id ? ' #'.$movement->source_id : '' }}<div>{{ $movement->event_key }}</div></td>
                            <td class="px-3 py-3 text-right"><a href="{{ route('loyalty.kardex.show', $movement) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50">Ver</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="px-6 py-12 text-center text-slate-500">No se encontraron movimientos para los filtros seleccionados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $movements->links() }}</div>
    </x-card>
</div>
@endsection
