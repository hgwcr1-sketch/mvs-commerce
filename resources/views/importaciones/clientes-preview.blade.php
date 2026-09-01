@extends('layouts.app')

@section('title', 'Revisar clientes')
@section('description', 'Vista previa de importación de clientes')

@section('content')
@php
    $validCount = collect($rows)->where('valid', true)->where('skipped', false)->count();
    $skippedCount = collect($rows)->where('skipped', true)->count();
    $errorCount = collect($rows)->where('valid', false)->count();
    $warningCount = collect($rows)->filter(fn ($row) => ! empty($row['warnings']))->count();
@endphp
<div class="mx-auto max-w-7xl space-y-6" data-customer-import-preview>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-amber-700">P32 · Vista previa</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-800">Revisar clientes</h1>
            <p class="mt-2 text-sm text-slate-600">Todavía no se ha creado ningún cliente.</p>
        </div>
        <a href="{{ route('importaciones.clientes') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 sm:w-auto">Cargar otro archivo</a>
    </header>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">Filas <strong class="block text-2xl text-slate-900">{{ count($rows) }}</strong></div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">Listas <strong class="block text-2xl">{{ $validCount }}</strong></div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">Omitidas <strong class="block text-2xl">{{ $skippedCount }}</strong></div>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">Con errores <strong class="block text-2xl">{{ $errorCount }}</strong></div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">Con advertencias <strong class="block text-2xl">{{ $warningCount }}</strong></div>
    </div>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-[900px] w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-600">
                    <tr><th class="px-4 py-3">Fila</th><th class="px-4 py-3">Identificación</th><th class="px-4 py-3">Nombre</th><th class="px-4 py-3">Teléfono / móvil</th><th class="px-4 py-3">Correo</th><th class="px-4 py-3">Estado</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($rows as $row)
                        <tr class="align-top {{ !$row['valid'] ? 'bg-red-50/50' : ($row['skipped'] ? 'bg-amber-50/50' : '') }}">
                            <td class="px-4 py-3 font-semibold text-slate-700">{{ $row['row_number'] }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ $row['identification'] ?: '—' }}</td>
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $row['name'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ $row['phone'] ?: ($row['mobile'] ?: '—') }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ $row['email'] ?: '—' }}</td>
                            <td class="px-4 py-3">
                                @if($row['skipped'])
                                    <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Omitida</span>
                                @elseif($row['valid'])
                                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ empty($row['warnings']) ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ empty($row['warnings']) ? 'Lista' : 'Advertencia' }}</span>
                                @else
                                    <span class="inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">Error</span>
                                    <ul class="mt-2 space-y-1 text-xs text-red-700">
                                        @foreach($row['errors'] as $error)
                                            <li><strong>{{ $error['field'] }}:</strong> {{ $error['message'] }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if(! empty($row['warnings']))
                                    <ul class="mt-2 space-y-1 text-xs text-amber-700">
                                        @foreach($row['warnings'] as $warning)
                                            <li><strong>{{ $warning['field'] }}:</strong> {{ $warning['message'] }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-600">La confirmación es transaccional: si una fila deja de ser válida, no se importa ninguna.</p>
        @if($errorCount === 0 && count($rows) > 0)
            <form action="{{ route('importaciones.clientes.import') }}" method="POST" onsubmit="return confirm('¿Confirmar la importación de {{ $validCount }} clientes?');">
                @csrf
                <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white sm:w-auto">Confirmar importación de {{ $validCount }}</button>
            </form>
        @else
            <p class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">Corrija todas las filas antes de confirmar.</p>
        @endif
    </div>
</div>
@endsection
