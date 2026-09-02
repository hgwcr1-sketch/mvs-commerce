@extends('layouts.app')
@section('title', 'Revisar fidelización P37')
@section('content')
@php
    $errorCount = collect($rows)->where('valid', false)->count();
    $ambiguousCount = collect($rows)->filter(fn ($row) => count($row['customer_candidates'] ?? []) > 1)->count();
@endphp
<div class="mx-auto max-w-7xl space-y-6" data-loyalty-migration-preview>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div><p class="text-sm font-semibold uppercase tracking-wide text-emerald-700">P37 · Vista previa</p><h1 class="mt-1 text-2xl font-bold text-slate-800">Conciliar fidelización</h1><p class="mt-2 text-sm text-slate-600">Lote: <strong>{{ $rows[0]['source_key'] ?? '—' }}</strong>. Aún no se crean cuentas ni movimientos.</p></div>
        <a href="{{ route('importaciones.fidelidad-migracion') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Cargar otro archivo</a>
    </header>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-xl border bg-white p-4 text-sm">Filas<strong class="block text-2xl">{{ count($rows) }}</strong></div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">Nombres ambiguos<strong class="block text-2xl">{{ $ambiguousCount }}</strong></div>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">Con errores<strong class="block text-2xl">{{ $errorCount }}</strong></div>
    </div>
    <form action="{{ route('importaciones.fidelidad-migracion.resolve') }}" method="POST" class="space-y-4">
        @csrf
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="overflow-x-auto"><table class="w-full min-w-[1100px] divide-y divide-slate-200 text-sm"><thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-600"><tr><th class="px-4 py-3">Fila</th><th class="px-4 py-3">Nombre / cliente</th><th class="px-4 py-3 text-right">Otorgados</th><th class="px-4 py-3 text-right">Utilizados</th><th class="px-4 py-3 text-right">Saldo</th><th class="px-4 py-3">Estado</th></tr></thead><tbody class="divide-y divide-slate-100">
            @foreach($rows as $row)
                <tr class="align-top {{ $row['valid'] ? '' : 'bg-red-50/50' }}">
                    <td class="px-4 py-3">{{ $row['row_number'] }}</td>
                    <td class="min-w-96 px-4 py-3"><p class="font-semibold text-slate-800">{{ $row['name'] ?: '—' }}</p>
                        @if(count($row['customer_candidates'] ?? []) > 1)
                            <label for="customer-{{ $row['row_number'] }}" class="mt-3 block text-xs font-semibold text-amber-800">Seleccione el cliente correcto</label>
                            <select id="customer-{{ $row['row_number'] }}" name="selections[{{ $row['row_number'] }}]" class="mt-1 min-h-11 w-full rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm">
                                <option value="">Sin seleccionar</option>
                                @foreach($row['customer_candidates'] as $candidate)
                                    <option value="{{ $candidate['id'] }}" @selected((int) ($row['customer_id'] ?? 0) === (int) $candidate['id'])>{{ $candidate['name'] }} | ID: {{ $candidate['identification'] ?: '—' }} | Tel: {{ $candidate['phone'] ?: '—' }} | Email: {{ $candidate['email'] ?: '—' }}</option>
                                @endforeach
                            </select>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">{{ $row['awarded_points'] ?? '—' }}</td><td class="px-4 py-3 text-right">{{ $row['used_points'] ?? '—' }}</td><td class="px-4 py-3 text-right">{{ $row['balance'] ?? '—' }}</td>
                    <td class="px-4 py-3">@if($row['valid'])<span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Lista</span>@else<span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">Error</span><ul class="mt-2 space-y-1 text-xs text-red-700">@foreach($row['errors'] as $error)<li><strong>{{ $error['field'] }}:</strong> {{ $error['message'] }}</li>@endforeach</ul>@endif</td>
                </tr>
            @endforeach
        </tbody></table></div></section>
        @if($ambiguousCount > 0)<button class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-amber-600 px-5 py-3 text-sm font-bold text-white sm:w-auto">Guardar selección de clientes</button>@endif
    </form>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-slate-600">La confirmación revalida clientes y ejecuta el lote completo dentro de una transacción.</p>@if($errorCount === 0)<form action="{{ route('importaciones.fidelidad-migracion.import') }}" method="POST" onsubmit="return confirm('¿Confirmar la migración P37?');">@csrf<button class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white sm:w-auto">Confirmar migración</button></form>@else<p class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">Resuelva o corrija todas las filas antes de confirmar.</p>@endif</div>
    @if($errorCount > 0)<a href="{{ route('importaciones.fidelidad-migracion.errors') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700">Descargar errores CSV</a>@endif
</div>
@endsection
