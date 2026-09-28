@extends('layouts.app')

@section('title', 'Nueva forma de pago')
@section('description', 'Crea un método de cobro personalizado para el POS.')

@section('content')
<div class="mx-auto max-w-5xl">
    <x-card>
        <x-slot:header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-slate-800">Nueva forma de pago</h2>
                <a href="{{ route('settings.pos.payment-methods.index') }}"
                   class="rounded-lg border border-slate-300 px-4 py-2 font-medium text-slate-700 hover:bg-slate-100">
                    Volver
                </a>
            </div>
        </x-slot:header>

        <form action="{{ route('settings.pos.payment-methods.store') }}" method="POST">
            @include('settings.pos.payment-methods._form')
        </form>
    </x-card>
</div>
@endsection
