@extends('layouts.portal')

@section('title', 'Programa de fidelización · '.$company->trade_name)

@section('content')

@php
    $points = static function ($value, bool $signed = false): string {
        $number = (float) $value;
        $formatted = rtrim(rtrim(number_format(abs($number), 4, ',', '.'), '0'), ',');
        return ($signed ? ($number >= 0 ? '+' : '-') : '').$formatted.' puntos';
    };
    $money = static fn ($value): string => '₡'.number_format((float) $value, 2, ',', '.');
    $decimal = static function ($value): string {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    };
@endphp

<div class="space-y-6">

    {{-- Encabezado con la identidad visual de la empresa (F31) --}}
    <header class="rounded-2xl bg-slate-900 px-5 py-6 text-white shadow-sm sm:px-8">
        <div class="flex items-center gap-4">
            @if($company->logo)
                <img src="{{ asset('storage/'.$company->logo) }}" alt="Logo de {{ $company->trade_name }}" class="h-14 w-14 shrink-0 rounded-xl border border-white/10 bg-white object-contain p-1.5">
            @else
                <div data-brand-initial class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-xl font-bold text-black" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($company->trade_name), 0, 1)) }}</div>
            @endif
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-amber-400">{{ $company->trade_name }}</p>
                <h1 class="mt-0.5 truncate text-xl font-bold sm:text-2xl">Programa de fidelización</h1>
            </div>
        </div>
        <p class="mt-4 truncate border-t border-white/10 pt-3 text-sm text-slate-300">{{ $customer->name }}</p>
    </header>

    @unless($module_active)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
            El programa de fidelización no está disponible por el momento.
        </div>
    @endunless

    @if($module_active)
        {{-- Saldo actual --}}
        <section aria-label="Saldo actual" class="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm sm:p-8">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Saldo actual</h2>
            <p class="mt-3 text-4xl font-bold text-slate-900 sm:text-5xl">{{ $points($balance_points) }}</p>
            @if($balance_money !== null)
                <p class="mt-2 text-base text-slate-600">Equivale a <span class="font-semibold text-emerald-700">{{ $money($balance_money) }}</span></p>
            @endif
        </section>

        {{-- Promociones vigentes: contenido administrable de la empresa (F35) --}}
        <section aria-label="Promociones" class="space-y-3">
            <h2 class="text-lg font-semibold text-slate-800">Promociones vigentes</h2>
            @forelse($promotions as $promotion)
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <h3 class="font-semibold text-slate-800">{{ $promotion->title }}</h3>
                    @if($promotion->description)
                        <p class="mt-1 break-words text-sm text-slate-600">{{ $promotion->description }}</p>
                    @endif
                    <p class="mt-1 text-xs text-slate-500">
                        Del {{ $promotion->starts_at->timezone($company->timezone ?: config('app.timezone'))->format('d/m/Y') }}
                        al {{ $promotion->ends_at->timezone($company->timezone ?: config('app.timezone'))->format('d/m/Y') }}
                    </p>
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500">No hay promociones vigentes.</p>
            @endforelse
        </section>

        {{-- Multiplicadores de puntos vigentes (mecanismo F12) --}}
        <section aria-label="Multiplicadores de puntos" class="space-y-3">
            <h2 class="text-lg font-semibold text-slate-800">Multiplicadores de puntos</h2>
            @forelse($multipliers as $multiplier)
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-slate-800">{{ $multiplier->name }}</h3>
                        <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800">x{{ $decimal($multiplier->multiplier) }} puntos</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Vigente hasta el {{ $multiplier->ends_at->timezone($company->timezone ?: config('app.timezone'))->format('d/m/Y') }}
                        @if($multiplier->branch)
                            · {{ $multiplier->branch->name }}
                        @endif
                    </p>
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500">No hay multiplicadores vigentes por el momento.</p>
            @endforelse
        </section>

        {{-- Premios disponibles --}}
        <section aria-label="Premios" class="space-y-3">
            <h2 class="text-lg font-semibold text-slate-800">Premios disponibles</h2>
            @forelse($rewards as $reward)
                <article class="flex items-start justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <div class="min-w-0">
                        <h3 class="font-semibold text-slate-800">{{ $reward->name }}</h3>
                        @if($reward->description)
                            <p class="mt-1 break-words text-sm text-slate-600">{{ $reward->description }}</p>
                        @endif
                    </div>
                    <span class="shrink-0 rounded-full bg-slate-900 px-3 py-1.5 text-xs font-bold text-white">{{ $points($reward->points_cost) }}</span>
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500">No hay premios disponibles por el momento.</p>
            @endforelse
        </section>
    @endif

    {{-- Historial de movimientos --}}
    <section aria-label="Historial de movimientos" class="space-y-3">
        <h2 class="text-lg font-semibold text-slate-800">Historial de movimientos</h2>

        @forelse($movements as $movement)
            <article class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ \App\Models\LoyaltyMovement::LABELS[$movement->type] ?? $movement->type }}</span>
                        <span class="text-xs text-slate-500">{{ $movement->effective_at?->format('d/m/Y H:i') }}</span>
                    </div>
                    <p class="mt-1.5 break-words text-sm font-medium text-slate-700">{{ $movement->description }}</p>
                    @if($movement->source_type && $movement->source_id)
                        <p class="mt-0.5 text-xs text-slate-400">{{ class_basename($movement->source_type) }} #{{ $movement->source_id }}</p>
                    @endif
                </div>
                <p class="shrink-0 text-right text-base font-bold {{ (float) $movement->points >= 0 ? 'text-emerald-700' : 'text-red-700' }}">{{ $points($movement->points, true) }}</p>
            </article>
        @empty
            <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500">Aún no tienes movimientos.</p>
        @endforelse

        <div>{{ $movements->onEachSide(0)->links() }}</div>
    </section>

</div>
@endsection
