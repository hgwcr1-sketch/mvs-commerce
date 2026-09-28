@extends('layouts.app')

@section('content')
<div class="mx-auto flex min-h-[65vh] max-w-2xl items-center justify-center py-4 sm:py-8">
    <section class="w-full rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-8" aria-labelledby="cash-required-title">
        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-100 text-2xl" aria-hidden="true">₡</div>
        <p class="mt-5 text-sm font-bold uppercase tracking-wide text-amber-700">Control de caja</p>
        <h1 id="cash-required-title" class="mt-2 text-2xl font-bold text-slate-950 sm:text-3xl">Abra una caja antes de usar el POS</h1>
        <p class="mt-3 text-base leading-7 text-slate-600">No existe una sesión de caja abierta compatible con su usuario en <strong>{{ $branch->name }}</strong>, {{ $company->trade_name }}.</p>
        <div class="mt-5 rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-700">El inicio de sesión en MVS Commerce no registra una jornada laboral. La apertura de caja controla únicamente las operaciones comerciales del POS.</div>
        @if($canOpenCash)
            <a href="{{ route('cash.open.create') }}" class="mt-6 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-950 px-5 font-bold text-white sm:w-auto">Ir a Apertura de Caja</a>
        @else
            <p class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">Solicite a un administrador una caja abierta compatible o el permiso para abrir caja.</p>
        @endif
    </section>
</div>
@endsection
