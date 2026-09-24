@extends('layouts.app')

@section('title', 'MVS RouteOS')

@section('content')
<div class="mx-auto max-w-3xl px-3 py-4 sm:px-4 sm:py-6">

    <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <div class="flex items-center gap-3">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary/15">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-[#B1922D]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                </svg>
            </div>
            <div class="min-w-0">
                <h1 class="text-xl font-bold text-slate-900 sm:text-2xl">MVS RouteOS</h1>
                <p class="text-sm text-slate-500">Ventas, Rutas y Cobros</p>
            </div>
        </div>

        <p class="mt-3 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800 sm:text-sm">
            {{ $company?->trade_name }} — Módulo en construcción. Las funciones se activarán por etapas.
        </p>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-3 sm:mt-6 sm:grid-cols-2">

        @foreach ($modules as $module)
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex items-start justify-between gap-2">
                    <h2 class="text-base font-semibold text-slate-900">{{ $module['label'] }}</h2>
                    <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $module['ready'] ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-500' }}">
                        {{ $module['ready'] ? 'Disponible' : 'Próximamente' }}
                    </span>
                </div>
                <p class="mt-1 text-sm text-slate-500">{{ $module['description'] }}</p>
                @unless ($module['enabled'])
                    <p class="mt-2 text-xs text-slate-400">Requiere permisos de RouteOS.</p>
                @endunless
            </div>
        @endforeach

    </div>

</div>
@endsection
