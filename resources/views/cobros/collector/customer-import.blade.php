@extends('cobros.layout')

@section('title', 'Importar clientes — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('collector.index') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Inicio</a>
            <h1 class="mt-1 text-2xl font-bold sm:text-3xl">Importar clientes de mi ruta</h1>
            <p class="mt-1 text-sm text-slate-500">Solo clientes nuevos de {{ $company->trade_name }}. Cada fila debe indicar una de sus rutas activas (columna <code>ruta</code>).</p>
        </div>
        <a href="{{ route('cobros.collector.customers.import.template') }}" class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-semibold hover:bg-slate-50">Descargar plantilla</a>
    </header>

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <ul class="list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <h2 class="font-bold">Cargar archivo</h2>
        <p class="mt-1 text-sm text-slate-600">Formatos .xlsx, .xls o .csv. Identificación 01–04. La importación queda visible para revisión del prestamista.</p>
        <form action="{{ route('cobros.collector.customers.import.preview') }}" method="POST" enctype="multipart/form-data" class="mt-4 space-y-4">
            @csrf
            <div>
                <label for="customer_file" class="mb-2 block text-sm font-semibold text-slate-700">Archivo de clientes</label>
                <input id="customer_file" name="customer_file" type="file" accept=".xlsx,.xls,.csv" required
                       class="block min-h-[44px] w-full rounded-xl border border-slate-300 bg-white p-3 text-sm">
            </div>
            <button type="submit" class="min-h-[44px] w-full rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover sm:w-auto">Revisar filas</button>
        </form>
    </section>
</div>
@endsection
