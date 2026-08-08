@extends('layouts.app')

@section('title', 'Editar Unidad de Medida')

@section('content')

<div class="mx-auto max-w-4xl">

    <div class="mb-6 flex items-center justify-between">

    <h1 class="text-2xl font-bold text-slate-800">
        Editar Unidad de Medida
    </h1>

    <a
        href="{{ route('unidades.index') }}"
        class="rounded-lg border border-slate-300 px-4 py-2 font-medium text-slate-700 hover:bg-slate-100">
        Volver
    </a>

</div>

    <form method="POST"
          action="{{ route('unidades.update', $unit) }}"
          class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">

        @csrf
        @method('PUT')

        @include('unidades.form')

    </form>

</div>

@endsection