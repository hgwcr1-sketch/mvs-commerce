@extends('cobros.layout')

@section('title', 'MVS CobrOS')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">MVS CobrOS</h1>
        <p class="mt-1 text-sm text-slate-500">Panel de CobrOS</p>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <div class="flex items-center gap-3">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary/15">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-[#B1922D]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
            </div>
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-slate-900">
                    {{ $company?->trade_name ?? 'Empresa' }}
                </h2>
                <p class="text-sm text-slate-500">
                    Módulo CobrOS activo
                </p>
            </div>
        </div>

        <div class="mt-4 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
            Panel de CobrOS — Módulo en construcción. Las funciones se activarán por etapas.
        </div>
    </div>

    <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wide">Próximamente</h3>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <h4 class="text-base font-semibold text-slate-900">Cartera</h4>
            <p class="mt-1 text-sm text-slate-500">Clientes con saldo pendiente por empresa.</p>
            <span class="mt-2 inline-flex shrink-0 items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                Próximamente
            </span>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <h4 class="text-base font-semibold text-slate-900">Cobranza</h4>
            <p class="mt-1 text-sm text-slate-500">Registro de pagos y avances.</p>
            <span class="mt-2 inline-flex shrink-0 items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                Próximamente
            </span>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <h4 class="text-base font-semibold text-slate-900">Rutas</h4>
            <p class="mt-1 text-sm text-slate-500">Asignación de cobradores a rutas.</p>
            <span class="mt-2 inline-flex shrink-0 items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                Próximamente
            </span>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <h4 class="text-base font-semibold text-slate-900">Reportes</h4>
            <p class="mt-1 text-sm text-slate-500">Indicadores de cobranza por empresa.</p>
            <span class="mt-2 inline-flex shrink-0 items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                Próximamente
            </span>
        </div>
    </div>
</div>
@endsection
