@extends('layouts.app')
@section('title', 'Cuentas por cobrar')
@section('content')
<div class="space-y-5">
    <div><h1 class="text-2xl font-bold text-slate-800">Cuentas por cobrar</h1><p class="text-sm text-slate-500">Créditos de la sucursal activa.</p></div>

    @can('cuentas_cobrar.editar')
        <form method="POST" action="{{ route('cuentas-por-cobrar.alert-days.update') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            @csrf @method('PUT')
            <div><label for="credit_alert_days" class="mb-1 block text-sm font-medium text-slate-700">Días para alerta próxima</label><select id="credit_alert_days" name="credit_alert_days" class="rounded-lg border-slate-300 text-sm">@foreach([1, 3, 5, 7, 15] as $days)<option value="{{ $days }}" @selected($alertDays === $days)>{{ $days }} días</option>@endforeach</select></div>
            <button class="rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">Guardar configuración</button>
        </form>
    @endcan

    <form method="GET" action="{{ route('cuentas-por-cobrar.index') }}" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-6">
        <input name="customer" value="{{ $filters['customer'] ?? '' }}" placeholder="Cliente" class="rounded-lg border-slate-300 text-sm">
        <select name="status" class="rounded-lg border-slate-300 text-sm"><option value="">Todos los estados</option>@foreach(['pending' => 'Pendiente', 'partial' => 'Parcial', 'paid' => 'Pagada', 'overdue' => 'Vencida', 'cancelled' => 'Cancelada'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select>
        <select name="due" class="rounded-lg border-slate-300 text-sm"><option value="">Todo vencimiento</option><option value="overdue" @selected(($filters['due'] ?? '') === 'overdue')>Vencidas</option><option value="upcoming" @selected(($filters['due'] ?? '') === 'upcoming')>Próximos {{ $alertDays }} días</option></select>
        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" aria-label="Fecha inicial" class="rounded-lg border-slate-300 text-sm">
        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" aria-label="Fecha final" class="rounded-lg border-slate-300 text-sm">
        <div class="flex gap-2"><button class="flex-1 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filtrar</button><a href="{{ route('cuentas-por-cobrar.index') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">Limpiar</a></div>
    </form>

    @php
        $statusLabels = ['pending' => 'Pendiente', 'partial' => 'Parcial', 'paid' => 'Pagada', 'overdue' => 'Vencida', 'cancelled' => 'Cancelada'];
        $statusClasses = ['pending' => 'bg-amber-100 text-amber-800', 'partial' => 'bg-sky-100 text-sky-800', 'paid' => 'bg-emerald-100 text-emerald-800', 'overdue' => 'bg-red-100 text-red-800', 'cancelled' => 'bg-slate-200 text-slate-700'];
    @endphp
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[980px] text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-600"><tr><th class="px-4 py-3 text-left">Cliente</th><th class="px-4 py-3 text-left">Venta</th><th class="px-4 py-3 text-center">Fecha</th><th class="px-4 py-3 text-center">Vencimiento</th><th class="px-4 py-3 text-right">Monto original</th><th class="px-4 py-3 text-right">Saldo</th><th class="px-4 py-3 text-center">Estado</th><th class="px-4 py-3 text-center">Acción</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($accounts as $account)
                    @php($effectiveStatus = $account->effective_status)
                    <tr class="hover:bg-slate-50/70"><td class="px-4 py-3 font-medium text-slate-800">{{ $account->customer->name }}</td><td class="px-4 py-3 text-slate-700">{{ $account->sale->sale_number }}</td><td class="px-4 py-3 text-center text-slate-600">{{ $account->issued_at->format('d/m/Y') }}</td><td class="px-4 py-3 text-center text-slate-600">{{ $account->due_date->format('d/m/Y') }}</td><td class="whitespace-nowrap px-4 py-3 text-right">₡{{ number_format((float) $account->original_amount, 0, ',', '.') }}</td><td class="whitespace-nowrap px-4 py-3 text-right font-semibold">₡{{ number_format((float) $account->balance_due, 0, ',', '.') }}</td><td class="px-4 py-3 text-center"><span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $statusClasses[$effectiveStatus] ?? 'bg-slate-100 text-slate-700' }}">{{ $statusLabels[$effectiveStatus] ?? $effectiveStatus }}</span></td><td class="px-4 py-3 text-center"><a href="{{ route('cuentas-por-cobrar.show', $account) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-100">Ver</a></td></tr>
                @empty
                    <tr><td colspan="8" class="p-8 text-center text-slate-500">No hay cuentas por cobrar con estos filtros.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $accounts->links() }}
</div>
@endsection
