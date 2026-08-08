@extends('layouts.app')

@section('title', 'Mi Dashboard')

@section('content')

<div class="space-y-6">

    <h1 class="text-2xl font-bold text-slate-800">
        Mi Dashboard
    </h1>

    <p class="text-slate-500">
        Panel de trabajo del vendedor.
    </p>


    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">


        <x-card>

            <div class="text-sm text-slate-500">
                Mis Ventas
            </div>

            <div class="mt-2 text-3xl font-bold text-slate-800">
                0
            </div>

        </x-card>


        <x-card>

            <div class="text-sm text-slate-500">
                Clientes
            </div>

            <div class="mt-2 text-3xl font-bold text-slate-800">
                0
            </div>

        </x-card>


        <x-card>

            <div class="text-sm text-slate-500">
                Pendientes
            </div>

            <div class="mt-2 text-3xl font-bold text-slate-800">
                0
            </div>

        </x-card>


    </div>


</div>

@endsection