@extends('layouts.app')

@section('title', 'Nuevo Producto')

@section('description', 'Registrar un nuevo producto.')

@section('content')

<div class="space-y-6">

    <div class="flex items-center justify-between">

        <div>

            <h1 class="text-2xl font-bold text-slate-800">
                
            </h1>

            <p class="text-slate-500">
                
            </p>

        </div>

        <a
            href="{{ route('productos.index') }}"
            class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">

            Volver

        </a>

    </div>

    <form
        action="{{ route('productos.store') }}"
        method="POST"
        enctype="multipart/form-data">

      @include('productos._form')

<div class="mt-6 flex justify-end gap-3">

    <a
        href="{{ route('productos.index') }}"
        class="rounded-lg border border-slate-300 px-5 py-2 hover:bg-slate-100">
        Cancelar
    </a>

    <button
        type="submit"
        class="rounded-lg bg-amber-500 px-5 py-2 font-semibold text-white hover:bg-amber-600">
        Guardar Producto
    </button>

</div>

    </form>

</div>

@endsection