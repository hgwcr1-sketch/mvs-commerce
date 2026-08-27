@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-2xl space-y-5">
    <section class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-7">
        <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Licencia de MVS Commerce</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-950">{{ $company->trade_name }}</h1>
        <p class="mt-3 text-slate-600">Estado: <strong>{{ ucfirst($license->status) }}</strong> · Plan: {{ $license->plan }}</p>
        @if($license->expires_at)<p class="mt-2 text-sm text-slate-600">Vencimiento: {{ $license->expires_at->format('d/m/Y H:i') }}</p>@endif
        @if(!$license->isOperable())<div class="mt-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">La operación está temporalmente bloqueada. Sus datos permanecen intactos y volverán a estar disponibles cuando el administrador de plataforma reactive la licencia.</div>@else<div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">La empresa puede operar normalmente.</div>@endif
    </section>
</div>
@endsection
