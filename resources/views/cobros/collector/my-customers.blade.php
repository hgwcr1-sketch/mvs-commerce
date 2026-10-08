@extends('cobros.layout')

@section('title', 'Mis clientes — MVS CobrOS')

@section('content')
<div class="space-y-4">
    <a href="{{ route('cobros.collector.portfolio') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Volver a mi cartera</a>

    <div>
        <h1 class="text-2xl font-bold text-slate-900">Mis clientes</h1>
        <p class="mt-1 text-sm text-slate-500">
            Personas de mi cartera, con o sin crédito activo. @if($activeRoute)Ruta {{ $activeRoute->name }} ·@endif {{ $company->trade_name }}
        </p>
    </div>

    <form method="GET" action="{{ route('cobros.collector.my-customers') }}" role="search" class="cobros-search">
        <label for="my-customers-search" class="sr-only">Buscar cliente</label>
        <input id="my-customers-search" type="search" name="q" value="{{ $search }}"
               placeholder="Buscar por nombre, identificación o teléfono"
               autocomplete="off" class="min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2.5 text-base">
        <button type="submit" class="mt-2 min-h-[44px] w-full rounded-xl bg-primary px-4 py-2.5 text-base font-semibold text-white hover:bg-primary-hover sm:mt-0 sm:w-auto">Buscar</button>
    </form>

    @if($customers->isEmpty())
        <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
            @if($search !== '')
                No hay clientes que coincidan con la búsqueda.
            @else
                Aún no tiene clientes registrados en su cartera.
            @endif
        </div>
    @else
        <ul class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white">
            @foreach($customers as $customer)
                <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="truncate text-base font-bold text-slate-900"><span class="inline-flex items-center gap-1.5">@include('cobros.components.credit-risk', ['customer' => $customer, 'company' => $company])<span class="truncate">{{ $customer->name }}</span></span></p>
                        <p class="mt-0.5 text-sm text-slate-500">
                            {{ $customer->identification }}
                            @if($customer->phone) · {{ $customer->phone }}@endif
                        </p>
                        <span @class([
                            'mt-2 inline-block rounded-full border px-3 py-1 text-xs font-bold',
                            'border-emerald-300 bg-emerald-50 text-emerald-800' => $customer->has_active_debt,
                            'border-slate-300 bg-slate-50 text-slate-600' => ! $customer->has_active_debt,
                        ])>{{ $customer->credit_status_label }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if(! $customer->has_active_debt)
                            <a href="{{ route('cobros.collector.credit-requests.create', ['customer' => $customer->id]) }}"
                               class="inline-flex min-h-[44px] items-center rounded-xl bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover">
                                NUEVA SOLICITUD
                            </a>
                        @endif
                        <a href="{{ route('cobros.collector.portfolio.show', $customer) }}"
                           class="inline-flex min-h-[44px] items-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Ver expediente
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
