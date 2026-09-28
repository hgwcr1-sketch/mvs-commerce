@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-2xl space-y-5 pb-24 md:pb-8">
    @if($config->isProduction())
        <div class="rounded-2xl bg-slate-950 p-4 text-center text-sm font-bold text-[#D4AF37]">AMBIENTE PRODUCCIÓN — documentos con validez fiscal</div>
    @else
        <div class="rounded-2xl bg-amber-100 p-4 text-center text-sm font-bold text-amber-900">PRUEBAS — SIN VALOR FISCAL</div>
    @endif

    <section class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-7">
        <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Centro de Facturación Electrónica</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-950">{{ $company->trade_name }}</h1>

        @if(session('status'))
            <div class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif

        @if($config->hasPending())
            <div class="mt-4 rounded-xl bg-amber-100 p-4 text-sm font-bold text-amber-900">
                Actualización requerida: hay una conexión nueva pendiente de verificación. La actual sigue funcionando.
                <a href="{{ route('fiscal.setup', ['step' => 'verificar']) }}" class="underline">Revisar ahora</a>
            </div>
        @endif

        @if($status === 'disabled')
            <div class="mt-4 rounded-xl bg-slate-100 p-4 text-sm text-slate-700">
                El módulo fiscal no está habilitado para esta empresa. El POS solo emite tiquetes internos.
                La habilitación y la cuota las administra Panel Maestro.
            </div>
        @else
            <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
                <span class="rounded-full px-3 py-1 font-bold
                    {{ $status === 'ready' ? 'bg-emerald-100 text-emerald-900' : ($status === 'attention' ? 'bg-rose-100 text-rose-900' : 'bg-amber-100 text-amber-900') }}">
                    {{ $statusLabel }}
                </span>
                <span class="rounded-full px-3 py-1 font-bold {{ $config->isProduction() ? 'bg-slate-950 text-[#D4AF37]' : 'bg-slate-100 text-slate-700' }}">
                    {{ $environmentLabel }}
                </span>
            </div>

            <dl class="mt-4 space-y-2 text-sm text-slate-700">
                <div class="flex justify-between gap-3"><dt>Emisor</dt><dd class="font-bold text-right">{{ $company->legal_name ?: $company->trade_name }} ({{ $company->identification_number ?: 'pendiente' }})</dd></div>
                <div class="flex justify-between gap-3"><dt>Actividad económica</dt><dd class="font-bold text-right">{{ $config->economic_activity ?: 'Pendiente' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Sucursal / Terminal</dt><dd class="font-bold text-right">{{ ($config->fiscal_branch_code ?: '—') . ' / ' . ($config->fiscal_terminal_code ?: '—') }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Llave registrada</dt><dd class="font-bold text-right">{{ $config->maskedKey() ?: 'Pendiente' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Última comprobación</dt><dd class="font-bold text-right">{{ $config->last_verified_at ? $config->last_verified_at->format('d/m/Y H:i') : 'Sin verificar' }}</dd></div>
            </dl>

            <div class="mt-4 grid grid-cols-3 gap-2 text-center text-sm">
                <div class="rounded-xl bg-emerald-50 p-3"><p class="text-2xl font-bold text-emerald-900">{{ $counts['accepted'] }}</p><p class="text-emerald-800">Aceptados</p></div>
                <div class="rounded-xl bg-rose-50 p-3"><p class="text-2xl font-bold text-rose-900">{{ $counts['rejected'] }}</p><p class="text-rose-800">Rechazados</p></div>
                <div class="rounded-xl bg-amber-50 p-3"><p class="text-2xl font-bold text-amber-900">{{ $counts['pending'] }}</p><p class="text-amber-800">En proceso</p></div>
            </div>

            <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-700">
                <p class="font-bold">Consumo del mes{{ $quotaText }}</p>
                @if(count($usage['by_type']) > 0)
                    <ul class="mt-2 space-y-1">
                        @foreach($usage['by_type'] as $type => $count)
                            <li>{{ $typeLabels[$type] ?? ('Documento ' . $type) }}: <strong>{{ $count }}</strong></li>
                        @endforeach
                    </ul>
                @endif
                @if($usage['overage'] > 0)
                    <p class="mt-2">Excedentes del mes: <strong>{{ $usage['overage'] }}</strong></p>
                @endif
            </div>
        @endif

        @can('fiscal.editar')
            <div class="mt-5 flex flex-wrap gap-2">
                @if($status === 'ready')
                    <a href="{{ route('fiscal.setup', ['step' => 'datos']) }}"
                       class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black hover:brightness-95">
                        Administrar configuración
                    </a>
                    <a href="{{ route('fiscal.setup', ['step' => 'conexion']) }}" class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 px-5 font-bold text-slate-800">Actualizar conexión</a>
                @else
                    <a href="{{ route('fiscal.setup', ['step' => 'datos']) }}"
                       class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black hover:brightness-95">
                        Completar configuración
                    </a>
                @endif
                <a href="{{ route('fiscal.series') }}" class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 px-5 font-bold text-slate-800">Series</a>
                <a href="{{ route('fiscal.switch') }}" class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 px-5 font-bold text-slate-800">Proveedor</a>
            </div>
        @endcan
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-bold text-slate-950">Documentos recientes</h2>
            <a href="{{ route('fiscal.history') }}" class="min-h-[44px] inline-flex items-center text-sm font-bold text-amber-700">Ver historial</a>
        </div>
        @if($recent->isEmpty())
            <p class="mt-3 text-sm text-slate-600">Aún no se ha emitido ningún documento.</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500">
                            <th class="py-2 pr-3">Tipo</th>
                            <th class="py-2 pr-3 hidden md:table-cell">Intento</th>
                            <th class="py-2 pr-3">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recent as $doc)
                            <tr class="border-t border-slate-100">
                                <td class="py-2 pr-3 font-bold">
                                    <a href="{{ route('fiscal.documents.show', $doc) }}" class="underline decoration-amber-500">{{ $typeLabels[$doc->document_type] ?? $doc->document_type }}</a>
                                </td>
                                <td class="py-2 pr-3 hidden md:table-cell">{{ $doc->attempt_number }}</td>
                                <td class="py-2 pr-3">{{ $statusLabels[$doc->status] ?? $doc->status }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <h2 class="text-lg font-bold text-slate-950">Diagnóstico</h2>
        <ul class="mt-3 space-y-2 text-sm">
            @foreach($diagnostic as $row)
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full {{ $row['ok'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $row['ok'] ? '✓' : '!' }}</span>
                    <span><strong>{{ $row['label'] }}:</strong> {{ $row['detail'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>
</div>
@endsection
