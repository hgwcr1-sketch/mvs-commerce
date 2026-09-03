@extends('layouts.app')
@section('title', 'Estado de migración P37')
@section('content')
@php
    $status = match ($run->status) {
        'pending' => 'Pendiente',
        'processing' => 'Procesando',
        'completed' => 'Completado',
        'failed' => 'Error',
        default => $run->status,
    };
@endphp
@if(in_array($run->status, ['pending', 'processing'], true))
    <meta http-equiv="refresh" content="5">
@endif
<div class="mx-auto max-w-4xl space-y-6">
    <header>
        <p class="text-sm font-semibold uppercase tracking-wide text-emerald-700">P37 · Procesamiento asíncrono</p>
        <h1 class="mt-1 text-2xl font-bold text-slate-800">Estado de migración</h1>
        <p class="mt-2 break-all text-sm text-slate-600">Lote: <strong>{{ $run->source_key }}</strong></p>
    </header>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm font-semibold text-slate-600">Estado</p>
        <p class="mt-1 text-2xl font-bold text-slate-900">{{ $status }}</p>
        @if($run->status === 'pending')<p class="mt-2 text-sm text-slate-600">El lote está en cola y comenzará cuando el worker esté disponible.</p>@endif
        @if($run->status === 'processing')<p class="mt-2 text-sm text-sky-700">La importación se ejecuta fuera de la petición HTTP. Esta página se actualiza automáticamente.</p>@endif
        @if($run->status === 'completed')<p class="mt-2 text-sm text-emerald-700">La migración terminó correctamente y no requiere otra confirmación.</p>@endif
        @if($run->status === 'failed')
            <p class="mt-2 text-sm text-red-700">La transacción fue revertida. Puede reintentar el mismo lote sin duplicar puntos.</p>
            <pre class="mt-3 whitespace-pre-wrap break-words rounded-xl bg-red-50 p-3 text-xs text-red-900">{{ $run->last_error }}</pre>
        @endif
    </section>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="rounded-xl border bg-white p-4 text-sm">Listos<strong class="block text-2xl">{{ $run->valid_count }}</strong></div>
        <div class="rounded-xl border bg-white p-4 text-sm">Pendientes<strong class="block text-2xl">{{ $run->pending_count }}</strong></div>
        <div class="rounded-xl border bg-white p-4 text-sm">Consolidadas<strong class="block text-2xl">{{ $run->consolidated_count }}</strong></div>
        <div class="rounded-xl border bg-white p-4 text-sm">Importados<strong class="block text-2xl">{{ $run->imported_count }}</strong></div>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        @if($run->status === 'failed')
            <form method="POST" action="{{ route('importaciones.fidelidad-migracion.retry', $run) }}">@csrf
                <button class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-red-700 px-5 py-3 text-sm font-bold text-white sm:w-auto">Reintentar lote</button>
            </form>
        @endif
        <a href="{{ route('importaciones.fidelidad-migracion') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700">Cargar otro archivo</a>
        <a href="{{ route('loyalty.dashboard') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-300 bg-white px-5 py-3 text-sm font-semibold text-emerald-700">Ir a fidelización</a>
    </div>
</div>
@endsection
