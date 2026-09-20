@extends('cobros.layout')

@section('title', 'Cartera — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Cartera del dueño</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $company->trade_name }} · Consulta de solo lectura</p>
    </div>

    <form method="GET" action="{{ route('cobros.owner.dashboard') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
            <label for="portfolio-branch" class="block text-sm font-medium text-slate-700">Sucursal</label>
            <select id="portfolio-branch" name="branch_id" class="min-h-11 w-full rounded-lg border-slate-300">
                <option value="">Todas las sucursales</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="min-h-11 self-end rounded-lg bg-primary px-4 py-2 font-semibold text-slate-950 hover:bg-primary-hover">Filtrar</button>
    </form>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach([
            'Total por cobrar' => $metrics['total_due'],
            'Total vencido' => $metrics['total_overdue'],
            'Cobrado hoy' => $metrics['collected_today'],
            'Clientes con saldo' => $metrics['customers_with_balance'],
            'Próximas a vencer' => $metrics['upcoming_count'],
            'Vencidas' => $metrics['overdue_count'],
        ] as $label => $value)
            <div class="min-w-0 rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="text-sm text-slate-500">{{ $label }}</h2>
                <p class="mt-2 break-words text-xl font-bold tabular-nums text-slate-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>
    <p class="text-xs text-slate-500">Importes con cuatro decimales, sin conversión de moneda. Próximos vencimientos: hoy y los siguientes {{ $alertDays }} días. Fecha: {{ $today->format('d/m/Y') }}.</p>

    <section aria-label="Cuentas por cobrar" class="space-y-3">
        <h2 class="text-lg font-semibold text-slate-900">Cuentas con saldo</h2>
        @forelse($accounts as $account)
            <article class="min-w-0 rounded-xl border border-slate-200 bg-white p-4">
                <h3 class="break-words font-semibold text-slate-900">{{ $account->customer?->name ?? 'Cliente no disponible' }}</h3>
                <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-slate-500">Documento / venta</dt><dd class="break-words">{{ $account->sale?->sale_number ?? 'Sin venta asociada' }}</dd></div>
                    <div><dt class="text-slate-500">Sucursal</dt><dd class="break-words">{{ $account->branch?->name ?? 'No disponible' }}</dd></div>
                    <div><dt class="text-slate-500">Emisión</dt><dd>{{ $account->issued_at?->format('d/m/Y') }}</dd></div>
                    <div><dt class="text-slate-500">Vencimiento</dt><dd>{{ $account->due_date?->format('d/m/Y') }}</dd></div>
                    <div><dt class="text-slate-500">Saldo</dt><dd class="break-words font-semibold tabular-nums">{{ $account->currency_code }} {{ $account->balance_due }}</dd></div>
                    <div><dt class="text-slate-500">Estado efectivo</dt><dd>
                        {{ $account->portfolio_status }}
                    </dd></div>
                </dl>
            </article>
        @empty
            <p class="rounded-xl border border-slate-200 bg-white p-4 text-slate-500">No hay cuentas con saldo para esta selección.</p>
        @endforelse
        {{ $accounts->links() }}
    </section>
</div>
@endsection
