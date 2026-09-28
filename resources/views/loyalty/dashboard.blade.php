@extends('layouts.app')

@section('title', 'Dashboard de Fidelización')
@section('description', 'Resumen operativo de oportunidades y puntos de la empresa activa.')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
        <div><h1 class="text-2xl font-semibold text-slate-800">Fidelización</h1><p class="mt-1 text-sm text-slate-500">Resumen de hoy para la empresa activa.</p></div>
        @can('fidelidad.oportunidades')<a href="{{ route('loyalty.opportunities.index') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-amber-500 px-4 py-2 font-semibold text-black hover:bg-amber-600 sm:w-auto">Ver oportunidades</a>@endcan
    </div>
    <section aria-labelledby="loyalty-indicators-title">
        <div class="mb-3">
            <h2 id="loyalty-indicators-title" class="text-lg font-semibold text-slate-800">Indicadores acumulados</h2>
            <p class="mt-1 text-sm text-slate-500">Totales globales de Fidelización para la empresa activa.</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            @foreach([
                'customers' => ['Clientes con cuenta', 'personas'],
                'total_earned' => ['Puntos generados', 'puntos'],
                'total_redeemed' => ['Puntos canjeados', 'puntos'],
                'total_expired' => ['Puntos vencidos', 'puntos'],
                'balance' => ['Saldo vigente', 'puntos'],
            ] as $key => [$label, $unit])
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <p class="text-sm font-medium text-slate-500">{{ $label }}</p>
                    <p class="mt-2 break-all text-2xl font-bold tabular-nums text-slate-800">{{ $indicators[$key] }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ $unit }}</p>
                </div>
            @endforeach
        </div>
    </section>
    <section aria-labelledby="loyalty-branches-title">
        <div class="mb-3">
            <h2 id="loyalty-branches-title" class="text-lg font-semibold text-slate-800">Actividad por sucursal</h2>
            <p class="mt-1 text-sm text-slate-500">Origen acumulado de los movimientos. El saldo de puntos es global para toda la empresa.</p>
        </div>
        <div class="space-y-3 md:hidden">
            @foreach($branchIndicators as $branch)
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="font-semibold text-slate-800">{{ $branch['branch_name'] }}</h3>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-slate-500">Clientes</dt><dd class="mt-1 font-semibold tabular-nums">{{ $branch['customers'] }}</dd></div>
                        <div><dt class="text-slate-500">Generados</dt><dd class="mt-1 font-semibold tabular-nums">{{ $branch['total_earned'] }}</dd></div>
                        <div><dt class="text-slate-500">Canjeados</dt><dd class="mt-1 font-semibold tabular-nums">{{ $branch['total_redeemed'] }}</dd></div>
                        <div><dt class="text-slate-500">Vencidos</dt><dd class="mt-1 font-semibold tabular-nums">{{ $branch['total_expired'] }}</dd></div>
                    </dl>
                </article>
            @endforeach
        </div>
        <div class="hidden overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm md:block">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">Sucursal</th>
                        <th scope="col" class="px-4 py-3 text-right">Clientes</th>
                        <th scope="col" class="px-4 py-3 text-right">Generados</th>
                        <th scope="col" class="px-4 py-3 text-right">Canjeados</th>
                        <th scope="col" class="px-4 py-3 text-right">Vencidos</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($branchIndicators as $branch)
                        <tr>
                            <th scope="row" class="whitespace-nowrap px-4 py-3 text-left font-medium text-slate-800">{{ $branch['branch_name'] }}</th>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $branch['customers'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $branch['total_earned'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $branch['total_redeemed'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $branch['total_expired'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach(['birthdays' => 'Cumpleaños hoy', 'inactive_30' => '30–59 días', 'inactive_60' => '60–89 días', 'inactive_90' => '90 días o más'] as $key => $label)
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-2 text-3xl font-bold text-slate-800">{{ $summary[$key] }}</p></div>
        @endforeach
    </div>
    <x-card><x-slot:header><h2 class="text-lg font-semibold">Movimientos generados hoy</h2></x-slot:header>
        <div class="grid gap-4 sm:grid-cols-3">
            <div><p class="text-sm text-slate-500">Bonos de cumpleaños</p><p class="text-2xl font-bold">{{ $summary['birthday_awards'] }}</p></div>
            <div><p class="text-sm text-slate-500">Bonos de retorno</p><p class="text-2xl font-bold">{{ $summary['return_awards'] }}</p></div>
            <div><p class="text-sm text-slate-500">Puntos por compras</p><p class="text-2xl font-bold">{{ number_format((float) $summary['purchase_points'], 2) }}</p></div>
        </div>
    </x-card>
</div>
@endsection
