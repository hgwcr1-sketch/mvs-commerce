@extends('layouts.app')

@section('title', 'Editar Categoría')

@section('description', 'Actualiza la información de la categoría.')

@section('content')

<div class="max-w-5xl mx-auto">

    <x-card>

        <<x-slot name="header">

    <div class="flex items-center justify-between">

        <h2 class="text-xl font-semibold text-slate-800">
            Editar Categoría
        </h2>

        <a
            href="{{ route('categorias.index') }}"
            class="rounded-lg border border-slate-300 px-4 py-2 font-medium text-slate-700 hover:bg-slate-100">
            Volver
        </a>

    </div>

</x-slot>

        <form action="{{ route('categorias.update', $categoria) }}" method="POST">

            @csrf
            @method('PUT')

            @include('categorias._form')

        </form>

    </x-card>

</div>

@endsection