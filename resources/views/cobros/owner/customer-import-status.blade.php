@extends('cobros.layout')

@section('title', 'Importación de clientes — MVS CobrOS')

@section('content')
@php
    $labels = ['uploaded' => 'Archivo recibido', 'analyzing' => 'Analizando', 'ready' => 'Listo para revisar', 'importing' => 'Importando', 'completed' => 'Completado', 'completed_with_issues' => 'Completado con incidencias', 'failed' => 'Fallido'];
    $rowLabels = ['new' => 'NUEVO', 'existing' => 'EXISTENTE — SE IGNORARÁ', 'duplicate_file' => 'DUPLICADO ARCHIVO — SE IGNORARÁ', 'conflict' => 'CONFLICTO', 'error' => 'ERROR', 'created' => 'CREADO'];
    $working = in_array($run->status, ['uploaded', 'analyzing', 'importing'], true);
    $counts = ['Total analizados' => $run->total_rows, 'Nuevos' => $run->new_count, 'Existentes ignorados' => $run->existing_count, 'Duplicados archivo ignorados' => $run->duplicate_count, 'Conflictos' => $run->conflict_count, 'Errores' => $run->error_count, 'Creados' => $run->created_count, 'Rechazados' => $run->rejected_count];
@endphp
<div class="space-y-5">
    <header class="space-y-2">
        <a href="{{ route('cobros.owner.customers.import') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Importar clientes</a>
        <h1 class="text-2xl font-bold">{{ $labels[$run->status] ?? $run->status }}</h1>
        <p class="break-words text-sm text-slate-600">{{ $run->original_filename ?? 'Archivo temporal purgado' }} · Importación #{{ $run->id }} · {{ $company->trade_name }}</p>
        @if(! $run->confirmed_at)<p class="text-sm">Todavía no se ha creado ningún cliente ni se han aplicado puntos.</p>@endif
    </header>

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <ul class="list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if($run->last_error)<p class="rounded-xl bg-red-50 p-4 text-sm text-red-800">{{ $run->last_error }}</p>@endif

    @if($working)
        <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            Procesados {{ $run->rows()->whereNotNull('processed_at')->count() }} / {{ $run->total_rows }} · Creados {{ $run->created_count }}
        </p>
        <a class="inline-flex min-h-[44px] items-center font-semibold text-amber-700" href="{{ route('cobros.owner.customers.import.status', $run->id) }}">Actualizar progreso</a>
        <script>setTimeout(function () { window.location.replace(@json(route('cobros.owner.customers.import.status', $run->id))); }, 5000);</script>
    @endif

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach($counts as $label => $count)
            <div class="rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-600">{{ $label }}
                <strong class="block text-2xl text-slate-900">{{ number_format($count, 0, ',', '.') }}</strong>
            </div>
        @endforeach
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead class="text-xs uppercase text-slate-500">
                    <tr>
                        <th class="p-3">Fila</th>
                        <th class="p-3">Identificación</th>
                        <th class="p-3">Nombre</th>
                        <th class="p-3">Teléfono</th>
                        <th class="p-3">Correo</th>
                        <th class="p-3 text-right">Puntos</th>
                        <th class="p-3">Resultado</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($rows as $record)
                    @php($row = $record->data ?? [])
                    <tr class="border-t align-top {{ in_array($record->kind, ['error', 'conflict']) ? 'bg-red-50' : '' }}">
                        <td class="p-3">{{ $record->source_row }}</td>
                        <td class="p-3">{{ $row['identification'] ?? '—' }}</td>
                        <td class="p-3">{{ $row['name'] ?? '—' }}</td>
                        <td class="p-3">{{ $row['phone'] ?? '—' }}</td>
                        <td class="break-all p-3">{{ $row['email'] ?? '—' }}</td>
                        <td class="p-3 text-right">{{ $row['initial_points'] ?? '0' }}</td>
                        <td class="max-w-sm p-3">
                            <strong>{{ $rowLabels[$record->kind] ?? $record->kind }}</strong>
                            <p class="mt-1">{{ $record->reason }}</p>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $rows->withPath(route('cobros.owner.customers.import.status', $run->id))->links() }}
    </section>

    @if($run->status === 'ready' && ! $run->purged_at)
        <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <p class="text-sm text-slate-600">{{ $run->existing_count }} clientes existentes serán ignorados. {{ $run->duplicate_count }} duplicados serán ignorados. No se modificará ningún cliente existente.</p>
            <form method="POST" action="{{ route('cobros.owner.customers.import.confirm') }}" class="mt-4">
                @csrf
                <input type="hidden" name="run_id" value="{{ $run->id }}">
                <button type="submit" class="min-h-[44px] w-full rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white sm:w-auto">Crear {{ $run->new_count }} clientes nuevos</button>
            </form>
        </section>
    @endif

    @if(($run->status === 'failed' || $working) && ! $run->purged_at)
        <div class="flex flex-col gap-3 sm:flex-row">
            <form method="POST" action="{{ route('cobros.owner.customers.import.retry', $run->id) }}">
                @csrf
                <button type="submit" class="min-h-[44px] w-full rounded-xl border border-slate-300 bg-white px-5 text-sm font-semibold sm:w-auto">Reanudar proceso</button>
            </form>
            <a href="{{ route('cobros.owner.customers.import.report', $run->id) }}" class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-semibold">Descargar reporte CSV</a>
        </div>
    @endif

    @if($run->confirmed_at && ! $working)
        <a href="{{ route('cobros.owner.customers.import.report', $run->id) }}" class="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-semibold">Descargar reporte CSV</a>
    @endif
</div>
@endsection
