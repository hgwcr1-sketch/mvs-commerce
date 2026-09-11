@extends('layouts.app')

@section('title', 'Nueva Toma de Inventario')

@section('content')
<div class="mx-auto max-w-3xl" data-responsive="360 768 1280">
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Nueva Toma de Inventario</h1>
            <p class="mt-1 text-sm text-slate-500">El conteo se realizará en la sucursal activa.</p>
        </div>
        <a href="{{ route('inventory-counts.index') }}"
           class="shrink-0 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
            ← Volver
        </a>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('inventory-counts.store') }}">
            @csrf

            <div class="space-y-6">
                {{-- Referencia --}}
                <div>
                    <label class="mb-2 block text-sm font-semibold text-slate-700">Referencia / Nombre</label>
                    <input type="text"
                           name="reference"
                           value="{{ old('reference') }}"
                           placeholder="Ej: Conteo mensual Septiembre, Inventario anual 2024..."
                           maxlength="80"
                           class="w-full rounded-xl border border-slate-300 px-4 py-3">
                    @error('reference')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-xs text-slate-500">Opcional. Para identificar la toma fácilmente.</p>
                </div>

                {{-- Notas --}}
                <div>
                    <label class="mb-2 block text-sm font-semibold text-slate-700">Observaciones</label>
                    <textarea name="notes"
                              rows="4"
                              maxlength="1000"
                              placeholder="Notas adicionales sobre esta toma..."
                              class="w-full rounded-xl border border-slate-300 px-4 py-3">{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-8 flex justify-end gap-3">
                <a href="{{ route('inventory-counts.index') }}"
                   class="rounded-xl border border-slate-300 px-6 py-3 font-semibold text-slate-700 hover:bg-slate-50">
                    Cancelar
                </a>
                <button type="submit"
                        class="rounded-xl bg-indigo-600 px-6 py-3 font-semibold text-white hover:bg-indigo-700">
                    Crear Toma (Borrador)
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
