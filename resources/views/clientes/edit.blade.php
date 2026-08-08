@extends('layouts.app')

@section('title', 'Editar Cliente')

@section('description', 'Actualizar información del cliente.')

@section('content')

<div class="space-y-6">

    <div class="flex items-center justify-between">

        <div>

            <h1 class="text-2xl font-bold text-slate-800"></h1>

            <p class="text-slate-500"></p>

        </div>

        <a
            href="{{ route('clientes.index') }}"
            class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">

            Volver

        </a>

    </div>

    <form
   action="{{ route('clientes.update', ['cliente' => $customer->id]) }}"
    method="POST"
    novalidate>

        @method('PUT')

        <x-card>

            <x-slot:header>

                <h3 class="text-lg font-semibold">

                    Información del Cliente

                </h3>

            </x-slot:header>

            @include('clientes._form')

            <x-slot:footer>

                <div class="flex justify-end gap-3">

                    <a
                        href="{{ route('clientes.index') }}"
                        class="rounded-lg border border-slate-300 px-5 py-2 hover:bg-slate-100">

                        Cancelar

                    </a>

                    <button
                        type="submit"
                        class="rounded-lg bg-amber-500 px-5 py-2 font-semibold text-white hover:bg-amber-600">

                        Actualizar Cliente

                    </button>

                </div>

            </x-slot:footer>

        </x-card>

    </form>

</div>

@endsection