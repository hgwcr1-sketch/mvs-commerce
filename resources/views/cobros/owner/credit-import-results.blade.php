@extends('cobros.layout')

@section('title', 'Resultado de importación de créditos — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <header>
        <a href="{{ route('cobros.owner.credits.import') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Importar créditos</a>
        <h1 class="mt-1 text-2xl font-bold sm:text-3xl">Resultado de la importación</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $company->trade_name }}</p>
    </header>

    <div class="flex flex-wrap gap-2 text-xs font-semibold">
        <span class="rounded-full bg-emerald-100 px-3 py-1 text-emerald-800">Importadas: {{ $totals['imported'] ?? 0 }}</span>
        <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">Ya existían (omitidas): {{ $totals['duplicate'] ?? 0 }}</span>
        <span class="rounded-full bg-red-100 px-3 py-1 text-red-800">Conflictos: {{ $totals['conflict'] ?? 0 }}</span>
        <span class="rounded-full bg-red-100 px-3 py-1 text-red-800">Inválidas: {{ $totals['invalid'] ?? 0 }}</span>
        <span class="rounded-full bg-red-100 px-3 py-1 text-red-800">Errores: {{ $totals['error'] ?? 0 }}</span>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead class="text-xs uppercase text-slate-500">
                    <tr>
                        <th class="p-3">Fila</th>
                        <th class="p-3">Identificación</th>
                        <th class="p-3">Referencia</th>
                        <th class="p-3">Saldo</th>
                        <th class="p-3">Resultado</th>
                        <th class="p-3">Detalles</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($results as $row)
                        <tr class="border-t align-top">
                            <td class="p-3">{{ $row['line'] }}</td>
                            <td class="p-3">{{ (string) ($row['data']['identification'] ?? '—') }}</td>
                            <td class="break-all p-3">{{ (string) ($row['data']['external_reference'] ?? '—') }}</td>
                            <td class="p-3">{{ (string) ($row['data']['balance'] ?? '—') }}</td>
                            <td class="p-3">
                                @if($row['status'] === 'imported')
                                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Importada</span>
                                @elseif($row['status'] === 'duplicate')
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Omitida</span>
                                @elseif($row['status'] === 'conflict')
                                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-800">Conflicto</span>
                                @elseif($row['status'] === 'invalid')
                                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-800">Inválida</span>
                                @else
                                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-800">Error</span>
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
    </section>
</div>
@endsection
