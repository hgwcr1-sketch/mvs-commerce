@extends('cobros.layout')

@section('title', $title.' — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">{{ $title }}</h1>
        <p class="mt-1 break-words text-sm text-slate-500">{{ $company->trade_name }}</p>
    </div>
    <div class="cobros-notice rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700"><h2 class="font-semibold">Disponible próximamente</h2><p class="mt-1">La información operativa de este portal estará disponible próximamente.</p></div>
    <section class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <h2 class="text-lg font-semibold text-slate-900">{{ $branchLabel }}</h2>
        <ul class="mt-3 space-y-3 text-sm text-slate-700">
            @forelse($branches as $branch)
                <li class="break-words">{{ $branch->name }}</li>
            @empty
                <li>No hay sucursales disponibles para este perfil.</li>
            @endforelse
        </ul>
    </section>
</div>
@endsection
