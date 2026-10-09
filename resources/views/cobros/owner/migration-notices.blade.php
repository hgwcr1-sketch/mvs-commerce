@extends('cobros.layout')

@section('title', 'Migraciones — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <header>
        <a href="{{ route('cobros.owner.dashboard') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Portal del Dueño</a>
        <h1 class="mt-1 text-2xl font-bold sm:text-3xl">Migraciones realizadas</h1>
        <p class="mt-1 text-sm text-slate-500">Importaciones directas de cobradores de {{ $company->trade_name }}. Coteje los totales con su sistema anterior; una revisión pendiente nunca bloquea clientes, créditos, rutas ni cobros.</p>
    </header>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <h2 class="font-bold">Filtros</h2>
        <form method="GET" action="{{ route('cobros.owner.migrations.notices') }}" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="filter-collector" class="mb-1 block text-sm font-semibold text-slate-700">Cobrador</label>
                <select id="filter-collector" name="collector" class="min-h-[44px] w-full rounded-xl border border-slate-300 bg-white p-3 text-sm">
                    <option value="">Todos</option>
                    @foreach($collectors as $collectorOption)
                        <option value="{{ $collectorOption->id }}" @selected($filters['collector'] === $collectorOption->id)>{{ $collectorOption->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-route" class="mb-1 block text-sm font-semibold text-slate-700">Ruta</label>
                <select id="filter-route" name="route" class="min-h-[44px] w-full rounded-xl border border-slate-300 bg-white p-3 text-sm">
                    <option value="">Todas</option>
                    @foreach($routes as $routeOption)
                        <option value="{{ $routeOption->id }}" @selected($filters['route'] === $routeOption->id)>{{ $routeOption->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-status" class="mb-1 block text-sm font-semibold text-slate-700">Estado</label>
                <select id="filter-status" name="status" class="min-h-[44px] w-full rounded-xl border border-slate-300 bg-white p-3 text-sm">
                    <option value="">Todos</option>
                    <option value="pending" @selected($filters['status'] === 'pending')>Pendiente</option>
                    <option value="reviewed" @selected($filters['status'] === 'reviewed')>Revisada</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="min-h-[44px] flex-1 rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover">Aplicar</button>
                <a href="{{ route('cobros.owner.migrations.notices') }}" class="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold hover:bg-slate-50">Limpiar</a>
            </div>
        </form>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        @if($notices->isEmpty())
            <p class="text-sm text-slate-500">No hay migraciones registradas con esos filtros.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-left text-sm">
                    <thead class="text-xs uppercase text-slate-500">
                        <tr>
                            <th class="p-3">Fecha</th>
                            <th class="p-3">Cobrador</th>
                            <th class="p-3">Ruta</th>
                            <th class="p-3">Tipo</th>
                            <th class="p-3">Detalle y cotejo</th>
                            <th class="p-3">Estado</th>
                            <th class="p-3">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($notices as $notice)
                            <tr class="border-t align-top">
                                <td class="p-3">{{ optional($notice->created_at)->format('d/m/Y H:i') }}</td>
                                <td class="p-3">{{ $notice->collector->name ?? '—' }}</td>
                                <td class="p-3">{{ $notice->route->name ?? '—' }}</td>
                                <td class="p-3">{{ $notice->source === 'credit_import' ? 'Créditos' : 'Clientes' }}</td>
                                <td class="p-3 text-xs text-slate-600">
                                    <p>{{ $notice->details }}</p>
                                    @if(is_array($notice->summary) && $notice->summary !== [])
                                        <ul class="mt-1 list-disc space-y-0.5 pl-4">
                                            @if($notice->source === 'credit_import')
                                                <li>Importados: {{ $notice->summary['imported'] ?? 0 }} · Duplicados: {{ $notice->summary['duplicates'] ?? 0 }} · Conflictos: {{ $notice->summary['conflicts'] ?? 0 }} · Inválidos: {{ $notice->summary['invalid'] ?? 0 }}</li>
                                                <li>Monto original total: ₡{{ number_format((float) ($notice->summary['original_total'] ?? 0), 2) }} · Saldo pendiente total: ₡{{ number_format((float) ($notice->summary['balance_total'] ?? 0), 2) }}</li>
                                            @else
                                                <li>Creados: {{ $notice->summary['created'] ?? 0 }} · Nuevos: {{ $notice->summary['new'] ?? 0 }} · Existentes: {{ $notice->summary['existing'] ?? 0 }} · Duplicados: {{ $notice->summary['duplicates'] ?? 0 }}</li>
                                                <li>Conflictos: {{ $notice->summary['conflicts'] ?? 0 }} · Errores: {{ $notice->summary['errors'] ?? 0 }} · Ignorados: {{ $notice->summary['ignored'] ?? 0 }} · Rechazados: {{ $notice->summary['rejected'] ?? 0 }}</li>
                                                @if(!empty($notice->summary['filename']))
                                                    <li>Archivo: {{ $notice->summary['filename'] }}</li>
                                                @endif
                                            @endif
                                        </ul>
                                    @endif
                                    @if($notice->source === 'customer_import' && $notice->source_id)
                                        <a href="{{ route('cobros.owner.customers.import.status', $notice->source_id) }}" class="mt-1 inline-flex min-h-[40px] items-center font-semibold text-amber-700">Ver detalle de la importación</a>
                                    @endif
                                </td>
                                <td class="p-3">
                                    @if($notice->status === 'pending')
                                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800">Pendiente</span>
                                    @else
                                        <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">Revisada</span>
                                        <p class="mt-1 text-xs text-slate-500">Por {{ $notice->reviewedBy->name ?? '—' }} · {{ optional($notice->reviewed_at)->format('d/m/Y H:i') }}</p>
                                    @endif
                                </td>
                                <td class="p-3">
                                    @if($notice->status === 'pending')
                                        <form method="POST" action="{{ route('cobros.owner.migrations.notices.review', $notice->id) }}">
                                            @csrf
                                            <button type="submit" class="min-h-[44px] rounded-xl border border-slate-300 px-4 text-sm font-semibold hover:bg-slate-50">Marcar como revisada</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-400">Sin acción</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $notices->links() }}
        @endif
    </section>
</div>
@endsection
