@extends('layouts.app')

@section('title', 'Dashboard de Fidelización')
@section('description', 'Resumen operativo de oportunidades y puntos de la empresa activa.')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div><h1 class="text-2xl font-semibold text-slate-800">Fidelización</h1><p class="mt-1 text-sm text-slate-500">Resumen de hoy para la empresa activa.</p></div>
        @can('fidelidad.oportunidades')<a href="{{ route('loyalty.opportunities.index') }}" class="rounded-lg bg-amber-500 px-4 py-2 font-semibold text-black hover:bg-amber-600">Ver oportunidades</a>@endcan
    </div>
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
