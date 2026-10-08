@extends('cobros.layout')

@section('title', 'Importar clientes — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('cobros.owner.customers') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Clientes</a>
            <h1 class="mt-1 text-2xl font-bold sm:text-3xl">Importar clientes</h1>
            <p class="mt-1 text-sm text-slate-500">Carga segura desde Excel o CSV para {{ $company->trade_name }}. Modo seguro: solo se crean clientes nuevos.</p>
        </div>
        <a href="{{ route('cobros.owner.customers.import.template') }}" class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-semibold hover:bg-slate-50">Descargar plantilla</a>
    </header>

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <p class="font-semibold">No se pudo procesar el archivo.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <h2 class="font-bold">1. Cargar archivo</h2>
        <p class="mt-1 text-sm text-slate-600">Formatos: .xlsx, .xls o .csv. Identificación 01–05, fechas AAAA-MM-DD. Los clientes existentes y los duplicados se ignoran sin modificar datos.</p>
        <form action="{{ route('cobros.owner.customers.import.preview') }}" method="POST" enctype="multipart/form-data" class="mt-4 space-y-4">
            @csrf
            <div>
                <label for="customer_file" class="mb-2 block text-sm font-semibold text-slate-700">2. Archivo de clientes</label>
                <input id="customer_file" name="customer_file" type="file" accept=".xlsx,.xls,.csv" required
                       class="block min-h-[44px] w-full rounded-xl border border-slate-300 bg-white p-3 text-sm">
            </div>
            <button type="submit" class="min-h-[44px] w-full rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover sm:w-auto">Revisar filas</button>
        </form>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <h2 class="font-bold">Importaciones recientes</h2>
        @if($runs->isEmpty())
            <p class="mt-2 text-sm text-slate-500">Todavía no hay importaciones para esta empresa.</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[560px] text-left text-sm">
                    <thead class="text-xs uppercase text-slate-500">
                        <tr>
                            <th class="p-3">#</th>
                            <th class="p-3">Archivo</th>
                            <th class="p-3">Estado</th>
                            <th class="p-3">Fecha</th>
                            <th class="p-3">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($runs as $run)
                            <tr class="border-t">
                                <td class="p-3">{{ $run->id }}</td>
                                <td class="break-all p-3">{{ $run->original_filename ?? 'Archivo temporal purgado' }}</td>
                                <td class="p-3">{{ $run->status }}</td>
                                <td class="p-3">{{ optional($run->created_at)->format('d/m/Y H:i') }}</td>
                                <td class="p-3">
                                    <a class="font-semibold text-amber-700" href="{{ route('cobros.owner.customers.import.status', $run->id) }}">Ver detalle</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $runs->links() }}
        @endif
    </section>
</div>
@endsection
