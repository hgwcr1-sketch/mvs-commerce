@extends('layouts.app')

@section('title', 'Editar Marca')

@section('content')

<div class="space-y-6">

    <div class="flex items-center justify-between">

        <h1 class="text-2xl font-bold text-slate-800">
            Editar Marca
        </h1>

        <a
            href="{{ route('marcas.index') }}"
            class="rounded-lg border border-slate-300 px-4 py-2 font-medium text-slate-700 hover:bg-slate-100">
            Volver
        </a>

    </div>

    <form method="POST" action="{{ route('marcas.update', $marca) }}">

        @csrf
        @method('PUT')

        @include('marcas._form')

    </form>

</div>

@endsection