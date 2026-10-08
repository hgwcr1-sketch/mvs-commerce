@extends('cobros.layout')

@section('title', 'Clientes — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('cobros.owner.dashboard') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Inicio / Cartera</a>
            <h1 class="mt-1 text-2xl font-bold sm:text-3xl">Clientes</h1>
            <p class="mt-1 text-sm text-slate-500">Clientes de {{ $company->trade_name }} con su saldo abierto y cobrador asignado.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('cobros.owner.customers.create') }}" class="inline-flex min-h-[44px] items-center rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover">Nuevo cliente</a>
            <a href="{{ route('cobros.owner.assignments') }}" class="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 px-5 text-sm font-semibold hover:bg-slate-50">Asignar cartera</a>
        </div>
    </header>

    <form method="GET" action="{{ route('cobros.owner.customers') }}" role="search" class="cobros-search">
        <label for="customers-search" class="sr-only">Buscar cliente</label>
        <input id="customers-search" type="search" name="q" value="{{ $search }}"
               placeholder="Buscar por nombre, identificación, teléfono o correo"
               autocomplete="off" class="min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base">
        <button type="submit" class="mt-2 min-h-[44px] w-full rounded-xl bg-primary px-4 py-2.5 text-base font-semibold text-white hover:bg-primary-hover sm:mt-0 sm:w-auto">Buscar</button>
    </form>

    <form method="POST" action="{{ route('cobros.owner.customers.credit-setting') }}" class="rounded-xl border border-slate-200 bg-white p-4">
        @csrf
        @method('PATCH')
        <input type="hidden" name="allow_collector_credit_on_customer_create" value="0">
        <label class="flex min-h-[44px] items-center gap-3 text-sm font-semibold text-slate-800">
            <input type="checkbox" name="allow_collector_credit_on_customer_create" value="1"
                   @checked($creditAssignmentEnabled) data-credit-toggle class="h-5 w-5 rounded border-slate-300 text-primary focus:ring-primary">
            Permitir que los cobradores asignen crédito al crear clientes
        </label>
        <label for="collector-credit-limit-max" class="mt-3 block text-sm font-semibold text-slate-800">
            Límite máximo de crédito que puede otorgar un cobrador
        </label>
        <div class="mt-1 flex min-h-[44px] items-center rounded-xl border border-slate-300 bg-white px-3">
            <span class="mr-2 text-slate-500">₡</span>
            <input id="collector-credit-limit-max" name="collector_customer_credit_limit_max"
                   type="text" inputmode="decimal" maxlength="16" value="{{ old('collector_customer_credit_limit_max', $company->collector_customer_credit_limit_max ?? '0.00') }}"
                   class="min-h-[42px] w-full border-0 px-1 text-right text-base focus:ring-0">
        </div>
        @error('collector_customer_credit_limit_max')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        <p class="mt-1 text-xs text-slate-500">Monto máximo por cliente, con dos decimales. Se exige cuando el permiso está activo.</p>
        <button type="submit" class="mt-3 min-h-[44px] rounded-xl border border-slate-300 px-4 text-sm font-semibold hover:bg-slate-50">Guardar configuración</button>
    </form>

    <section class="space-y-4" aria-label="Listado de clientes">
        @forelse($rows as $row)
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="break-words text-base font-semibold text-slate-900"><span class="inline-flex items-center gap-1.5">@include('cobros.components.credit-risk', ['customer' => $row['customer'], 'company' => $company, 'level' => $riskLevels[(int) $row['customer']->id]['level'] ?? null])<span>{{ $row['customer']->name }}</span></span></h2>
                        <p class="mt-0.5 break-words text-sm text-slate-500">
                            {{ $row['customer']->identification ?: 'Sin identificación' }}
                            @if($row['customer']->phone || $row['customer']->mobile)
                                · {{ $row['customer']->phone ?: $row['customer']->mobile }}
                            @endif
                        </p>
                        <p class="mt-1 text-sm">
                            Cobrador:
                            @if($row['collector'])
                                <strong class="text-emerald-700">{{ $row['collector']->name }}</strong>
                            @else
                                <span class="font-semibold text-slate-500">Sin asignar</span>
                            @endif
                        </p>
                    </div>
                    <div class="text-right">
                        <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Saldo abierto</span>
                        <span class="text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $row['balance'], 'currency' => $company->currency])</span>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="{{ route('cobros.owner.customers.show', $row['customer']) }}" class="inline-flex min-h-[44px] items-center rounded-xl bg-primary px-4 text-sm font-bold text-white hover:bg-primary-hover">Ver detalle</a>
                    <a href="{{ route('cobros.owner.customers.edit', $row['customer']) }}" class="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold hover:bg-slate-50">Editar</a>
                </div>
            </article>
        @empty
            <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
                @if($search !== '')
                    No hay clientes que coincidan con la búsqueda.
                @else
                    No hay clientes registrados en esta empresa.
                @endif
            </div>
        @endforelse
    </section>

    <div class="flex flex-wrap items-center gap-2">
        {{ $customers->links() }}
    </div>
</div>
@endsection
