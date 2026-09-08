@extends('layouts.app')

@section('content')
<div class="min-w-0 space-y-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-bold text-slate-800 sm:text-2xl">Traslados de Inventario</h1>
            <p class="mt-1 text-sm text-slate-500">Productos en movimiento entre sucursales.</p>
        </div>
        <a href="{{ route('transferencias.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-amber-500 px-5 py-3 text-sm font-semibold text-slate-950 hover:bg-amber-600">Nuevo traslado</a>
    </header>
    @include('transferencias._messages')
    <form method="GET" action="{{ route('transferencias.index') }}" class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-end sm:p-5">
        <div class="min-w-0 flex-1">
            <label for="status" class="mb-2 block text-sm font-semibold text-slate-700">Estado</label>
            <select name="status" id="status" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm">
                <option value="">Todos los estados</option>
                @foreach($statusLabels as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="min-h-11 rounded-xl bg-slate-800 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-700">Filtrar</button>
        @if(request('status'))
            <a href="{{ route('transferencias.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700">Limpiar</a>
        @endif
    </form>
    @if($transfers->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-12 text-center text-slate-500">
            No hay traslados para mostrar{{ request('status') ? ' con este estado' : '' }}.
        </div>
    @else
        <div class="space-y-3 md:hidden">
            @foreach($transfers as $transfer)
                <article class="min-w-0 space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="break-all text-sm font-bold text-slate-800">{{ $transfer->transfer_number }}</h2>
                        @include('transferencias._status')
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div class="min-w-0"><dt class="text-slate-500">Origen</dt><dd class="break-words font-medium text-slate-800">{{ $transfer->fromBranch?->name ?? '—' }}</dd></div>
                        <div class="min-w-0"><dt class="text-slate-500">Destino</dt><dd class="break-words font-medium text-slate-800">{{ $transfer->toBranch?->name ?? '—' }}</dd></div>
                        <div><dt class="text-slate-500">Productos</dt><dd>{{ $transfer->items->count() }}</dd></div>
                        <div><dt class="text-slate-500">Creado</dt><dd>{{ $transfer->created_at?->format('d/m/Y H:i') }}</dd></div>
                    </dl>
                    <p class="break-words text-xs text-slate-500">Creado por {{ $transfer->user?->name ?? 'Usuario no disponible' }}</p>
                    @include('transferencias._actions', ['detail' => false])
                </article>
            @endforeach
        </div>
        <div class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm md:block">
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            @foreach(['Traslado', 'Origen / destino', 'Estado', 'Productos', 'Creado por / fecha', 'Acciones'] as $heading)
                                <th scope="col" class="px-5 py-4 font-semibold">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach($transfers as $transfer)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-800">{{ $transfer->transfer_number }}</td>
                                <td class="px-5 py-4"><div>{{ $transfer->fromBranch?->name ?? '—' }}</div><div class="mt-1 text-slate-500">→ {{ $transfer->toBranch?->name ?? '—' }}</div></td>
                                <td class="px-5 py-4">@include('transferencias._status')</td>
                                <td class="px-5 py-4 tabular-nums">{{ $transfer->items->count() }}</td>
                                <td class="px-5 py-4"><div>{{ $transfer->user?->name ?? 'Usuario no disponible' }}</div><div class="mt-1 whitespace-nowrap text-xs text-slate-500">{{ $transfer->created_at?->format('d/m/Y H:i') }}</div></td>
                                <td class="px-5 py-4">@include('transferencias._actions', ['detail' => false])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
    {{ $transfers->links() }}
</div>
@endsection
