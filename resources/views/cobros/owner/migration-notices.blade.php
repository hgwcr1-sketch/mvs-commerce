@extends('cobros.layout')

@section('title', 'Migraciones pendientes — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <header>
        <a href="{{ route('cobros.owner.dashboard') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Portal del Dueño</a>
        <h1 class="mt-1 text-2xl font-bold sm:text-3xl">Migración pendiente de revisar</h1>
        <p class="mt-1 text-sm text-slate-500">Importaciones directas de cobradores de {{ $company->trade_name }}. Solo lectura; no bloquean operaciones.</p>
    </header>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        @if($notices->isEmpty())
            <p class="text-sm text-slate-500">No hay migraciones registradas.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="text-xs uppercase text-slate-500">
                        <tr>
                            <th class="p-3">Fecha</th>
                            <th class="p-3">Cobrador</th>
                            <th class="p-3">Ruta</th>
                            <th class="p-3">Tipo</th>
                            <th class="p-3">Detalle</th>
                            <th class="p-3">Estado</th>
                            <th class="p-3">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($notices as $notice)
                            <tr class="border-t">
                                <td class="p-3">{{ optional($notice->created_at)->format('d/m/Y H:i') }}</td>
                                <td class="p-3">{{ $notice->collector->name ?? '—' }}</td>
                                <td class="p-3">{{ $notice->route->name ?? '—' }}</td>
                                <td class="p-3">{{ $notice->source === 'credit_import' ? 'Créditos' : 'Clientes' }}</td>
                                <td class="p-3">{{ $notice->details }}</td>
                                <td class="p-3">
                                    @if($notice->status === 'pending')
                                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800">Pendiente</span>
                                    @else
                                        <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">Revisada</span>
                                    @endif
                                </td>
                                <td class="p-3">
                                    @if($notice->status === 'pending')
                                        <form method="POST" action="{{ route('cobros.owner.migrations.notices.review', $notice->id) }}">
                                            @csrf
                                            <button type="submit" class="min-h-[44px] rounded-xl border border-slate-300 px-4 text-sm font-semibold hover:bg-slate-50">Marcar revisada</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-400">{{ optional($notice->reviewed_at)->format('d/m/Y') }}</span>
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
