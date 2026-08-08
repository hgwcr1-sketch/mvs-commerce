@extends('layouts.app')

@section('title', 'Nueva Categoría')

@section('description', 'Crea una nueva categoría para organizar tus productos.')

@section('content')

<div class="max-w-5xl mx-auto">

    <x-card>

       <x-slot name="header">

    <div class="flex items-center justify-between">

        <h2 class="text-xl font-semibold text-slate-800">
            Nueva Categoría
        </h2>

        <a
            href="{{ route('categorias.index') }}"
            class="rounded-lg border border-slate-300 px-4 py-2 font-medium text-slate-700 hover:bg-slate-100">
            Volver
        </a>

    </div>

</x-slot>

        <form action="{{ route('categorias.store') }}" method="POST">

            @include('categorias._form', [
                'categoria' => new \App\Models\ProductCategory()
            ])

        </form>

    </x-card>

</div>

@endsection