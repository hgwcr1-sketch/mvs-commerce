@extends('layouts.app')

@section('title', 'Importar XML')

@section('content')
<div class="mb-6 flex items-center justify-between">
    <h2 class="text-xl font-semibold text-slate-800">
        Importar XML
    </h2>

    <a
        href="{{ route('compras.index') }}"
        class="rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-100">
        Volver
    </a>
</div>

<x-card>
    <form
        method="POST"
        action="{{ route('compras.import.xml') }}"
        enctype="multipart/form-data">
        @csrf

        <div>
            <label for="file" class="text-sm">
                Archivo XML
            </label>

            <input
                id="file"
                name="file"
                type="file"
                accept=".xml,text/xml,application/xml"
                required
                class="mt-1 block w-full rounded-lg border px-3 py-2">
        </div>

        <button
            type="submit"
            class="mt-5 rounded-lg bg-amber-500 px-5 py-2 text-white">
            Subir
        </button>
    </form>
</x-card>
@endsection
