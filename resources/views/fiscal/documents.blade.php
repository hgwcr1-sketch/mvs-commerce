@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-7xl space-y-5 pb-24 md:pb-8">
    <section class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-7">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Facturación Electrónica</p>
                <h1 class="mt-2 text-2xl font-bold text-slate-950">
                    @if($scope === 'pendientes') Pendientes / No enviados
                    @elseif($scope === 'rechazados') Rechazados / Errores
                    @else Documentos @endif
                </h1>
                <p class="mt-1 text-sm text-slate-500">Fuente de verdad MVS: consecutivos y claves provienen del proveedor/Hacienda. Nada se reinventa aquí.</p>
            </div>
            <div class="flex flex-wrap gap-2 text-sm">
                <a href="{{ route('fiscal.documents', ['scope' => 'pendientes']) }}" class="min-h-[44px] inline-flex items-center rounded-full px-4 {{ $scope === 'pendientes' ? 'bg-[#D4AF37] font-bold text-black' : 'bg-slate-100 text-slate-700' }}">Pendientes</a>
                <a href="{{ route('fiscal.documents', ['scope' => 'rechazados']) }}" class="min-h-[44px] inline-flex items-center rounded-full px-4 {{ $scope === 'rechazados' ? 'bg-[#D4AF37] font-bold text-black' : 'bg-slate-100 text-slate-700' }}">Rechazados</a>
                <a href="{{ route('fiscal.documents') }}" class="min-h-[44px] inline-flex items-center rounded-full px-4 {{ $scope === null ? 'bg-[#D4AF37] font-bold text-black' : 'bg-slate-100 text-slate-700' }}">Todos</a>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Documentos aceptados</p>
                <p class="mt-1 text-2xl font-bold text-emerald-900">{{ $summary['accepted'] }}</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-amber-700">IVA facturado</p>
                <p class="mt-1 text-2xl font-bold text-amber-900">₡{{ number_format((float) $summary['iva'], 2, ',', '.') }}</p>
            </div>
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-rose-700">Rechazados / Error</p>
                <p class="mt-1 text-2xl font-bold text-rose-900">{{ $summary['rejected'] }}</p>
            </div>
            <div class="rounded-xl border border-sky-200 bg-sky-50 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-sky-700">Pendientes / Procesando</p>
                <p class="mt-1 text-2xl font-bold text-sky-900">{{ $summary['pending'] }}</p>
            </div>
        </div>

        <form method="GET" action="{{ route('fiscal.documents') }}" class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @if($scope)<input type="hidden" name="scope" value="{{ $scope }}">@endif
            <label class="text-sm text-slate-600">Desde
                <input type="date" name="from" value="{{ $filters['from'] }}" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
            </label>
            <label class="text-sm text-slate-600">Hasta
                <input type="date" name="to" value="{{ $filters['to'] }}" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
            </label>
            <label class="text-sm text-slate-600">Tipo
                <select name="type" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
                    <option value="">Todos</option>
                    @foreach($typeLabels as $code => $label)
                        <option value="{{ $code }}" @selected($filters['type'] === $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-slate-600">Estado
                <select name="status" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
                    <option value="">Todos</option>
                    @foreach($statusLabels as $code => $label)
                        <option value="{{ $code }}" @selected($filters['status'] === $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-slate-600">Ambiente
                <select name="environment" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
                    <option value="">Todos</option>
                    <option value="production" @selected($filters['environment'] === 'production')>Producción</option>
                    <option value="sandbox" @selected($filters['environment'] === 'sandbox')>Pruebas</option>
                </select>
            </label>
            <label class="text-sm text-slate-600">Sucursal
                <select name="branch" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
                    <option value="">Todas</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($filters['branchId'] === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-slate-600 sm:col-span-2">Buscar
                <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Receptor, cédula, clave o consecutivo" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
            </label>
            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <button type="submit" class="min-h-[44px] rounded-xl bg-[#D4AF37] px-5 font-bold text-black hover:bg-[#B1922D]">Filtrar</button>
                <a href="{{ route('fiscal.documents', $scope ? ['scope' => $scope] : []) }}" class="min-h-[44px] inline-flex items-center rounded-xl bg-slate-100 px-4 font-bold text-slate-700">Limpiar</a>
            </div>
        </form>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        @if($scope === 'pendientes')
            @if($pendingSales->isEmpty())
                <p class="text-sm text-slate-600">Sin ventas FE/TE pendientes actuales. Las ventas históricas protegidas por el corte fiscal no se listan aquí.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead>
                            <tr class="text-left text-slate-500">
                                <th class="py-2 pr-3">Fecha</th>
                                <th class="py-2 pr-3">Venta</th>
                                <th class="py-2 pr-3">Tipo</th>
                                <th class="py-2 pr-3">Receptor</th>
                                <th class="py-2 pr-3">Total</th>
                                <th class="py-2 pr-3">Razón</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pendingSales as $sale)
                                <tr class="border-t border-slate-100">
                                    <td class="py-2 pr-3">{{ optional($sale->completed_at ?? $sale->created_at)->format('d/m/Y H:i') }}</td>
                                    <td class="py-2 pr-3 font-bold">{{ $sale->sale_number }}</td>
                                    <td class="py-2 pr-3">{{ \App\Models\Sale::receiptLabel($sale->document_type) }}</td>
                                    <td class="py-2 pr-3">{{ $sale->customer?->name ?? '—' }}</td>
                                    <td class="py-2 pr-3">₡{{ number_format((float) $sale->total, 2, ',', '.') }}</td>
                                    <td class="py-2 pr-3 text-xs text-slate-600">{{ $pendingReasons[$sale->id] ?? 'Aún no procesado por el flujo de emisión.' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $pendingSales->links() }}</div>
            @endif
        @else
            @if($documents->isEmpty())
                <p class="text-sm text-slate-600">No hay documentos para estos filtros. El historial fiscal nunca se borra.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead>
                            <tr class="text-left text-slate-500">
                                <th class="py-2 pr-3">Fecha / hora</th>
                                <th class="py-2 pr-3">Tipo</th>
                                <th class="py-2 pr-3">Consecutivo</th>
                                <th class="py-2 pr-3">Receptor</th>
                                <th class="py-2 pr-3 hidden lg:table-cell">Identificación</th>
                                <th class="py-2 pr-3 text-right">Total</th>
                                <th class="py-2 pr-3 text-right">IVA</th>
                                <th class="py-2 pr-3">Estado</th>
                                <th class="py-2 pr-3 hidden md:table-cell">Ambiente</th>
                                <th class="py-2 pr-3 hidden lg:table-cell">Sucursal</th>
                                <th class="py-2 pr-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($documents as $doc)
                                @php
                                    $isRejected = in_array($doc->status, ['rejected', 'error'], true);
                                    $isAccepted = $doc->status === 'accepted';
                                @endphp
                                <tr class="border-t border-slate-100 {{ $isRejected ? 'bg-rose-50/40' : ($isAccepted ? 'bg-emerald-50/30' : '') }}" data-status="{{ $doc->status }}">
                                    <td class="py-2 pr-3">{{ $doc->created_at->format('d/m/Y H:i') }}</td>
                                    <td class="py-2 pr-3 font-bold">{{ $typeLabels[$doc->document_type] ?? $doc->document_type }}</td>
                                    <td class="py-2 pr-3 font-mono text-xs">{{ $doc->consecutivo ?: '—' }}</td>
                                    <td class="py-2 pr-3">{{ $doc->sale?->customer?->name ?? '—' }}</td>
                                    <td class="py-2 pr-3 hidden lg:table-cell">{{ $doc->sale?->customer?->identification ?? '—' }}</td>
                                    <td class="py-2 pr-3 text-right">{{ $doc->sale ? '₡' . number_format((float) $doc->sale->total, 2, ',', '.') : '—' }}</td>
                                    <td class="py-2 pr-3 text-right">{{ $doc->sale ? '₡' . number_format((float) $doc->sale->tax_total, 2, ',', '.') : '—' }}</td>
                                    <td class="py-2 pr-3">
                                        @if($isAccepted)
                                            <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800">Aceptado</span>
                                        @elseif($isRejected)
                                            <span class="inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-800">Rechazado</span>
                                            @if($doc->last_error_code)
                                                <span class="block text-xs text-slate-500">{{ $doc->last_error_code }}</span>
                                            @endif
                                        @else
                                            <span class="inline-flex rounded-full bg-sky-100 px-2 py-0.5 text-xs font-bold text-sky-800">{{ $statusLabels[$doc->status] ?? $doc->status }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3 hidden md:table-cell">{{ $doc->environment === 'production' ? 'Producción' : 'Pruebas' }}</td>
                                    <td class="py-2 pr-3 hidden lg:table-cell">{{ $doc->sale?->branch?->name ?? '—' }}</td>
                                    <td class="py-2 pr-3">
                                        <div class="flex flex-wrap gap-2">
                                            <a href="{{ route('fiscal.documents.show', $doc) }}" class="min-h-[40px] inline-flex items-center rounded-lg bg-slate-100 px-3 text-xs font-bold text-slate-700">Detalle</a>
                                            @if($doc->sale_id)
                                                <a href="{{ route('pos.receipt.pdf', ['sale' => $doc->sale_id, 'format' => 'letter']) }}" class="min-h-[40px] inline-flex items-center rounded-lg bg-slate-100 px-3 text-xs font-bold text-slate-700">PDF</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $documents->links() }}</div>
            @endif
        @endif
        <a href="{{ route('fiscal.index') }}" class="mt-4 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Volver al portal fiscal</a>
    </section>
</div>
@endsection
