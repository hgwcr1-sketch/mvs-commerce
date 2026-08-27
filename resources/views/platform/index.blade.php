@extends('layouts.platform')
@section('title', 'Empresas')
@section('content')
<div class="space-y-6" data-platform-dashboard>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-bold uppercase tracking-wide text-amber-700">Propietario de plataforma</p><h1 class="mt-1 text-2xl font-bold sm:text-3xl">Empresas en MVS Commerce</h1><p class="mt-2 text-sm text-slate-600">Vista administrativa global. Los datos operativos permanecen dentro de cada empresa.</p></div><a href="{{ route('platform.companies.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-amber-500 px-5 font-bold text-slate-950">Nueva empresa</a></header>
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Resumen">
        @foreach(['companies' => 'Empresas', 'active_companies' => 'Activas', 'branches' => 'Sucursales', 'users' => 'Usuarios'] as $key => $label)
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-bold uppercase text-slate-500">{{ $label }}</p><p class="mt-2 text-2xl font-bold">{{ $totals[$key] }}</p></article>
        @endforeach
    </section>
    <form method="GET" class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row">
        <label class="flex-1 text-sm font-semibold">Buscar empresa<input name="search" value="{{ request('search') }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-4" placeholder="Nombre o identificación"></label>
        <button class="min-h-11 rounded-xl bg-slate-950 px-5 font-semibold text-white sm:self-end">Buscar</button>
    </form>
    <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse($companies as $company)
            <a href="{{ route('platform.companies.show', $company) }}" class="flex min-h-48 flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-amber-400">
                <div class="flex items-start justify-between gap-3"><h2 class="font-bold">{{ $company->trade_name }}</h2><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $company->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">{{ $company->is_active ? 'Activa' : 'Inactiva' }}</span></div>
                <p class="mt-2 text-sm text-slate-500">{{ $company->legal_name ?: 'Sin razón social' }}</p>
                <p class="mt-3 text-sm font-semibold text-slate-700">Licencia: {{ ucfirst($company->license?->status ?? 'sin configurar') }}@if($company->license?->expires_at) · {{ $company->license->expires_at->format('d/m/Y') }}@endif</p>
                <dl class="mt-auto grid grid-cols-2 gap-3 pt-6 text-sm"><div><dt class="text-slate-500">Sucursales</dt><dd class="font-bold">{{ $company->branches_count }}</dd></div><div><dt class="text-slate-500">Usuarios</dt><dd class="font-bold">{{ $company->users_count }}</dd></div></dl>
            </a>
        @empty<p class="rounded-2xl bg-white p-6 text-slate-500">No hay empresas para mostrar.</p>@endforelse
    </section>
    {{ $companies->links() }}
</div>
@endsection
