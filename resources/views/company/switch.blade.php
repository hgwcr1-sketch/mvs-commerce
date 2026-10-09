@extends('layouts.app')

@section('title', 'Cambiar de empresa')

@section('content')
<div class="mx-auto max-w-lg space-y-4 px-3 py-4 sm:px-0">
    <header>
        <h1 class="text-2xl font-bold text-slate-900">Cambiar de empresa</h1>
        <p class="mt-1 text-sm text-slate-500">
            Seleccione el prestamista/empresa donde desea operar. Sus permisos,
            rutas y modalidad se aplican por empresa; nada se hereda de la anterior.
        </p>
    </header>

    @if($errors->any())
        <div role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-800">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    @if($memberships->isEmpty())
        <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
            No tiene empresas activas y autorizadas disponibles.
        </div>
    @elseif($memberships->count() === 1 && (int) $memberships->first()->id === $activeCompanyId)
        <div class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-600">
            Solo está vinculado a una empresa: <strong>{{ $memberships->first()->trade_name }}</strong>.
        </div>
    @else
        <div class="space-y-3" role="group" aria-label="Empresas disponibles">
            @foreach($memberships as $membership)
                @php
                    $isActive = (int) $membership->id === $activeCompanyId;
                    $modalityKey = $membership->modality_key ?? null;
                    $modalityLabel = $modalityKey && \App\Services\Cobros\CobrosModalityCatalog::has($modalityKey)
                        ? \App\Services\Cobros\CobrosModalityCatalog::label($modalityKey)
                        : null;
                @endphp

                @if($isActive)
                    <div class="flex min-h-[56px] w-full items-center justify-between gap-3 rounded-xl border-2 border-primary bg-primary/10 px-5 py-4 text-left">
                        <div class="min-w-0">
                            <p class="truncate text-lg font-bold text-slate-900">{{ $membership->trade_name }}</p>
                            <p class="text-xs font-semibold uppercase tracking-wide text-primary-hover">
                                Empresa activa
                                @if($modalityLabel) · {{ $modalityLabel }} @endif
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full bg-primary px-3 py-1 text-xs font-bold text-slate-900">Actual</span>
                    </div>
                @else
                    <form method="POST" action="{{ route('company.switch.store') }}">
                        @csrf
                        <input type="hidden" name="company_id" value="{{ $membership->id }}">
                        <button type="submit"
                            class="flex min-h-[56px] w-full items-center justify-between gap-3 rounded-xl border-2 border-slate-200 bg-white px-5 py-4 text-left text-lg font-bold text-slate-900 hover:border-primary hover:bg-primary/10">
                            <span class="min-w-0">
                                <span class="block truncate">{{ $membership->trade_name }}</span>
                                @if($modalityLabel)
                                    <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $modalityLabel }}</span>
                                @endif
                            </span>
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
                        </button>
                    </form>
                @endif
            @endforeach
        </div>
    @endif

    <p class="text-center text-xs text-slate-500">
        El cambio no cierra su sesión. Si cierra una caja, deberá terminarla antes de cambiar.
    </p>
</div>
@endsection
