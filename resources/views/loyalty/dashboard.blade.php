@extends('layouts.app')

@section('title', 'Dashboard de Fidelización')
@section('description', 'Resumen operativo de oportunidades y puntos de la empresa activa.')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div><h1 class="text-2xl font-semibold text-slate-800">Fidelización</h1><p class="mt-1 text-sm text-slate-500">Resumen de hoy para la empresa activa.</p></div>
        @can('fidelidad.oportunidades')<a href="{{ route('loyalty.opportunities.index') }}" class="rounded-lg bg-amber-500 px-4 py-2 font-semibold text-black hover:bg-amber-600">Ver oportunidades</a>@endcan
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
