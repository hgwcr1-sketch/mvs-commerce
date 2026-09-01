@extends('layouts.app')
@section('title', 'Migrar inventario P36')
@section('content')
<div class="mx-auto max-w-4xl space-y-6" data-inventory-migration>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div><p class="text-sm font-semibold uppercase tracking-wide text-cyan-700">Centro de Datos · P36</p><h1 class="mt-1 text-2xl font-bold text-slate-800">Inventario inicial e histórico</h1><p class="mt-2 text-sm leading-6 text-slate-600">Saldo inicial fija el stock declarado. Movimiento histórico crea Kardex conciliado sin cambiar el stock actual.</p></div>
        <a href="{{ route('data-center.imports') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Volver</a>
    </header>
    @if($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"><p class="font-semibold">No se pudo procesar el archivo.</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="font-bold text-slate-800">1. Plantilla vigente</h2><p class="mt-1 text-sm text-slate-600">Use una clave de origen nueva y común a todas las filas. Cada clave de fila debe ser única.</p></div><a href="{{ route('importaciones.inventario-migracion.template') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Descargar plantilla Excel</a></div>
        <form action="{{ route('importaciones.inventario-migracion.preview') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-4">
            @csrf
            <div><label for="migration_file" class="mb-2 block text-sm font-semibold text-slate-700">2. Archivo P36</label><input id="migration_file" name="migration_file" type="file" accept=".xlsx,.xls,.csv" required class="block min-h-11 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm"><p class="mt-2 text-xs text-slate-500">Máximo 10 MB. XLSX, XLS o CSV. La vista previa no escribe datos.</p></div>
            <fieldset class="rounded-xl border border-cyan-200 bg-cyan-50 p-4">
                <legend class="px-2 text-sm font-bold text-cyan-900">CSV legado: CODIGO / CODIGO BARRA / DESCRIPCION / EXISTENCIA</legend>
                <p class="mb-4 text-sm text-cyan-900">Complete estos datos solo para ese formato. La sucursal elegida se aplicará a todas las filas.</p>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div><label for="legacy_branch_id" class="mb-1 block text-sm font-semibold text-slate-700">Sucursal destino</label><select id="legacy_branch_id" name="legacy_branch_id" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3"><option value="">Seleccione</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int) old('legacy_branch_id', $branchId) === $branch->id)>{{ $branch->name }} ({{ $branch->code }})</option>@endforeach</select></div>
                    <div><label for="legacy_source_key" class="mb-1 block text-sm font-semibold text-slate-700">Clave única del lote</label><input id="legacy_source_key" name="legacy_source_key" value="{{ old('legacy_source_key') }}" placeholder="MYM-SAN-RAMON-2026" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3"></div>
                    <div><label for="legacy_occurred_at" class="mb-1 block text-sm font-semibold text-slate-700">Fecha del saldo inicial</label><input id="legacy_occurred_at" name="legacy_occurred_at" type="datetime-local" value="{{ old('legacy_occurred_at') }}" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3"></div>
                </div>
            </fieldset>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900"><strong>saldo_inicial:</strong> cantidad es el stock final y puede actualizar <code>branch_product</code>.<br><strong>movimiento_historico:</strong> requiere entrada/salida, stock anterior y nuevo; solo añade Kardex histórico.</div>
            <button class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-cyan-700 px-5 py-3 text-sm font-bold text-white sm:w-auto">Revisar archivo</button>
        </form>
    </section>
</div>
@endsection
