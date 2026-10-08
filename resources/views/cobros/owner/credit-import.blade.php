@extends('cobros.layout')

@section('title', 'Importar créditos históricos — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('cobros.owner.customers') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Clientes</a>
            <h1 class="mt-1 text-2xl font-bold sm:text-3xl">Importar créditos históricos</h1>
            <p class="mt-1 text-sm text-slate-500">Carga desde Excel o CSV para {{ $company->trade_name }}. Cada fila crea su propia CxC; las reimportaciones no duplican registros.</p>
        </div>
        <a href="{{ route('cobros.owner.credits.import.template') }}" class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-semibold hover:bg-slate-50">Descargar plantilla</a>
    </header>

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <p class="font-semibold">No se pudo procesar el archivo.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if(session('cobros_credit_import_error'))
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ session('cobros_credit_import_error') }}</div>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <h2 class="font-bold">1. Cargar archivo</h2>
        <p class="mt-1 text-sm text-slate-600">Formatos .xlsx, .xls o .csv (máx. 500 filas). Vista previa sin escritura: nada se guarda hasta confirmar.</p>
        <form action="{{ route('cobros.owner.credits.import.preview') }}" method="POST" enctype="multipart/form-data" class="mt-4 space-y-4">
            @csrf
            <div>
                <label for="credit_file" class="mb-2 block text-sm font-semibold text-slate-700">2. Archivo de créditos</label>
                <input id="credit_file" name="credit_file" type="file" accept=".xlsx,.xls,.csv" required
                       class="block min-h-[44px] w-full rounded-xl border border-slate-300 bg-white p-3 text-sm">
            </div>
            <button type="submit" class="min-h-[44px] w-full rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover sm:w-auto">Previsualizar filas</button>
        </form>
    </section>

    @isset($preview)
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <h2 class="font-bold">3. Vista previa de {{ $filename }}</h2>
            <div class="mt-2 flex flex-wrap gap-2 text-xs font-semibold">
                <span class="rounded-full bg-emerald-100 px-3 py-1 text-emerald-800">Listas: {{ $totals['ready'] ?? 0 }}</span>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">Duplicadas (no se duplican): {{ $totals['duplicate'] ?? 0 }}</span>
                <span class="rounded-full bg-red-100 px-3 py-1 text-red-800">Conflictos: {{ $totals['conflict'] ?? 0 }}</span>
                <span class="rounded-full bg-red-100 px-3 py-1 text-red-800">Inválidas: {{ $totals['invalid'] ?? 0 }}</span>
            </div>

            @if(($totals['ready'] ?? 0) === 0)
                <p class="mt-4 text-sm text-slate-600">No hay filas listas para importar.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="text-xs uppercase text-slate-500">
                            <tr>
                                <th class="p-3">Fila</th>
                                <th class="p-3">Identificación</th>
                                <th class="p-3">Referencia</th>
                                <th class="p-3">Saldo</th>
                                <th class="p-3">Estado</th>
                                <th class="p-3">Detalles</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($preview as $row)
                                <tr class="border-t align-top">
                                    <td class="p-3">{{ $row['line'] }}</td>
                                    <td class="p-3">{{ (string) ($row['data']['identification'] ?? '—') }}</td>
                                    <td class="break-all p-3">{{ (string) ($row['data']['external_reference'] ?? '—') }}</td>
                                    <td class="p-3">{{ (string) ($row['data']['balance'] ?? '—') }}</td>
                                    <td class="p-3">
                                        @if($row['status'] === 'ready')
                                            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Lista</span>
                                        @elseif($row['status'] === 'duplicate')
                                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Duplicada</span>
                                        @else
                                            <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-800">{{ $row['status'] === 'conflict' ? 'Conflicto' : 'Inválida' }}</span>
                                        @endif
                                    </td>
                                    <td class="p-3 text-xs text-slate-600">
                                        @if($row['errors'] !== [])
                                            <ul class="list-disc space-y-1 pl-4">
                                                @foreach($row['errors'] as $error)<li>{{ $error }}</li>@endforeach
                                            </ul>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <form action="{{ route('cobros.owner.credits.import.confirm') }}" method="POST" class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    @csrf
                    <label class="flex min-h-[44px] items-start gap-3 text-sm text-slate-700">
                        <input type="checkbox" name="confirm" value="1" required class="mt-1 h-5 w-5">
                        <span>Confirmo la importación de <strong>{{ $totals['ready'] ?? 0 }} fila(s) lista(s)</strong>. Las filas duplicadas se omiten y los conflictos no sobrescriben datos existentes.</span>
                    </label>
                    <button type="submit" class="mt-3 min-h-[44px] w-full rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover sm:w-auto">Confirmar importación</button>
                </form>
            @endif
        </section>
    @endisset
</div>
@endsection
