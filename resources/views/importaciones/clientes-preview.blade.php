@extends('layouts.app')
@section('title', 'Importación de clientes')
@section('description', 'Modo seguro: crear solo clientes nuevos')
@section('content')
@php
    $labels = ['uploaded' => 'Archivo recibido', 'analyzing' => 'Analizando', 'ready' => 'Listo para revisar', 'importing' => 'Importando', 'completed' => 'Completado', 'completed_with_issues' => 'Completado con incidencias', 'failed' => 'Fallido'];
    $rowLabels = ['new' => 'NUEVO', 'existing' => 'EXISTENTE — SE IGNORARÁ', 'duplicate_file' => 'DUPLICADO ARCHIVO — SE IGNORARÁ', 'conflict' => 'CONFLICTO', 'error' => 'ERROR', 'created' => 'CREADO'];
    $working = in_array($run->status, ['uploaded', 'analyzing', 'importing'], true);
    $finished = in_array($run->status, \App\Models\CustomerImportRun::TERMINAL, true);
    $counts = ['Total analizados' => $run->total_rows, 'Nuevos' => $run->new_count, 'Existentes ignorados' => $run->existing_count, 'Duplicados archivo ignorados' => $run->duplicate_count, 'Conflictos' => $run->conflict_count, 'Errores' => $run->error_count, 'Creados' => $run->created_count, 'Rechazados' => $run->rejected_count];
@endphp
<div class="mx-auto min-w-0 max-w-7xl space-y-5" data-customer-import-preview>
    <header class="space-y-3">
        <a href="{{ route('importaciones.clientes') }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-amber-700">Volver a Importar clientes</a>
        <h1 class="text-2xl font-bold text-slate-800">{{ $labels[$run->status] ?? $run->status }}</h1>
        <p class="break-words text-sm text-slate-600">{{ $run->original_filename ?? 'Archivo temporal purgado' }} · Importación #{{ $run->id }}</p>
        <p class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">Modo seguro: Crear solo clientes nuevos. Los clientes que ya existen serán ignorados. No se modificarán sus datos ni sus puntos.</p>
        @if(!$run->confirmed_at)<p class="text-sm">Todavía no se ha creado ningún cliente ni se han aplicado puntos.</p>@endif
    </header>
    @if($errors->any())<div class="rounded-xl bg-red-50 p-4 text-red-800">{{ $errors->first() }}</div>@endif
    @if($run->last_error)<p class="rounded-xl bg-red-50 p-4 text-red-800">{{ $run->last_error }}</p>@endif
    @if($working)
        <div role="status" aria-live="polite" class="rounded-xl border bg-white p-4">
            @if($run->status === 'importing')
                Procesados {{ $run->rows()->whereNotNull('processed_at')->count() }} / {{ $run->total_rows }} · Creados {{ $run->created_count }}
            @else
                Analizados {{ $run->analyzed_rows }} clientes · Fila {{ $run->current_row }} / {{ $run->last_source_row ?? 'por determinar' }}
            @endif
            <p class="mt-2 text-sm">Puede volver a esta pantalla desde Importar clientes. El trabajo continúa en segundo plano.</p>
            <a class="mt-2 inline-flex min-h-11 items-center font-semibold text-amber-700" href="{{ route('importaciones.clientes.status', $run->id) }}">Actualizar progreso</a>
        </div>
        <script>setTimeout(() => window.location.replace(@json(route('importaciones.clientes.status', $run->id))), 5000);</script>
    @endif
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach($counts as $label => $count)
            <div class="rounded-xl border bg-white p-3 text-sm text-slate-600">{{ $label }}<strong class="block text-2xl text-slate-900">{{ number_format($count, 0, ',', '.') }}</strong></div>
        @endforeach
    </div>
    @if($run->purged_at)
        <p class="rounded-xl bg-slate-100 p-4">Los detalles temporales expiraron. Se conserva el resultado y la trazabilidad de los clientes creados.</p>
    @else
        <section class="overflow-hidden rounded-xl border bg-white">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1050px] text-left text-sm">
                    <thead class="bg-slate-100"><tr>@foreach(['Fila', 'Identificación', 'Nombre', 'Teléfono / móvil', 'Correo', 'Puntos iniciales', 'Estado / motivo'] as $label)<th class="p-3">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach($rows as $record)
                        @php($row = $record->data ?? [])
                        <tr class="border-t align-top {{ in_array($record->kind, ['error', 'conflict']) ? 'bg-red-50' : '' }}">
                            <td class="p-3">{{ $record->source_row }}</td>
                            <td class="p-3">{{ $row['identification'] ?? '—' }}<span class="block text-xs">Tipo {{ $row['identification_type'] ?? '—' }}</span></td>
                            <td class="p-3">{{ $row['name'] ?? '—' }}</td>
                            <td class="p-3">{{ $row['phone'] ?? '—' }}<span class="block">{{ $row['mobile'] ?? '' }}</span></td>
                            <td class="p-3 break-all">{{ $row['email'] ?? '—' }}</td>
                            <td class="p-3 text-right">{{ $row['initial_points'] ?? '0' }}</td>
                            <td class="max-w-sm p-3"><strong>{{ $rowLabels[$record->kind] ?? $record->kind }}</strong><p class="mt-1">{{ $record->reason }}</p>
                                @foreach($row['warnings'] ?? [] as $warning)<p class="mt-1 text-xs text-amber-800">Advertencia · {{ $warning['field'] }}: {{ $warning['message'] }}</p>@endforeach
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        {{ $rows->withPath(route('importaciones.clientes.status', $run->id))->links() }}
    @endif
    @if($run->status === 'ready' && !$run->purged_at)
        <div class="sticky bottom-20 z-10 space-y-3 rounded-xl border bg-white p-4 shadow-lg md:bottom-4">
            <p class="text-sm">{{ $run->existing_count }} clientes existentes serán ignorados. {{ $run->duplicate_count }} duplicados serán ignorados. No se modificará ningún cliente existente.</p>
            <p class="text-sm">Los conflictos y errores se rechazan; las filas nuevas válidas pueden importarse.</p>
            <form method="POST" action="{{ route('importaciones.clientes.import') }}">@csrf
                <input type="hidden" name="run_id" value="{{ $run->id }}">
                <button class="min-h-11 w-full rounded-xl bg-emerald-700 px-4 py-3 font-bold text-white sm:w-auto">Crear {{ $run->new_count }} clientes nuevos</button>
            </form>
        </div>
    @endif
    @if(($run->status === 'failed' || $working) && !$run->purged_at)
        <form method="POST" action="{{ route('importaciones.clientes.retry', $run->id) }}">@csrf<button class="min-h-11 rounded-xl border bg-white px-4 py-3 font-semibold">Reanudar proceso</button></form>
    @endif
    <a href="{{ route('importaciones.clientes.report', $run->id) }}" class="inline-flex min-h-11 items-center rounded-xl border bg-white px-4 py-3 font-semibold">Descargar reporte CSV</a>
</div>
@endsection
