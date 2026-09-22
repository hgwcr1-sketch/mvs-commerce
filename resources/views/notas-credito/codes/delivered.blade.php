@extends('layouts.app')

@section('title', 'Código regenerado')

@section('description', 'Entregue este nuevo código al cliente.')

@section('content')

<div class="mx-auto max-w-lg space-y-6">

    <div class="rounded-2xl border border-primary bg-white p-4 sm:p-6">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-bold text-slate-800">
                Código regenerado
            </h2>

            <button
                type="button"
                onclick="window.print()"
                class="min-h-11 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-black hover:bg-primary-hover">
                Imprimir
            </button>
        </div>

        <div class="mt-5 space-y-1">
            <p class="text-xs font-black uppercase text-slate-500">
                Nota de Crédito
            </p>
            <p class="font-mono text-lg font-bold text-slate-800">
                {{ $delivery['credit_note_number'] }}
            </p>
        </div>

        <div class="mt-5 space-y-1">
            <p class="text-xs font-black uppercase text-slate-500">
                Nuevo código
            </p>
            <p class="font-mono text-3xl font-black tracking-widest text-black">
                {{ $delivery['application_code'] }}
            </p>
        </div>

        <div class="mt-5 rounded-xl bg-primary/10 p-4">
            <p class="text-sm font-semibold text-slate-800">
                Entregue este nuevo código al cliente. El código anterior ya no es válido.
            </p>
            <p class="mt-1 text-xs text-slate-500">
                Este código se muestra una única vez y no podrá consultarse nuevamente.
            </p>
        </div>
    </div>

    <a
        href="{{ route('notas-credito.codes.index') }}"
        class="block min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-center font-semibold text-slate-700 hover:bg-slate-100">
        Volver a la lista
    </a>

</div>

@endsection