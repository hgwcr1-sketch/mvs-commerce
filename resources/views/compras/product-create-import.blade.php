@extends('layouts.app')

@section('title', 'Crear producto')

@section('content')
<div class="mb-6">
    <h2 class="text-xl font-semibold text-slate-800">
        Crear producto desde importación
    </h2>
</div>

<x-card>
    <form method="POST" action="{{ route('compras.import.product.store') }}">
        @csrf
        <input type="hidden" name="row_key" value="{{ $rowKey }}">

        <div class="grid gap-4">
            <div>
                <label class="text-sm">Código</label>
                <input
                    name="code"
                    value="{{ old('code', $code) }}"
                    required
                    class="w-full rounded-lg border px-3 py-2">
            </div>

            <div>
                <label class="text-sm">Nombre producto</label>
                <input
                    name="name"
                    value="{{ old('name', $name) }}"
                    required
                    class="w-full rounded-lg border px-3 py-2">
            </div>

            <div>
                <label class="text-sm">Costo</label>
                <input
                    name="cost"
                    value="{{ old('cost', $cost) }}"
                    required
                    class="w-full rounded-lg border px-3 py-2">
            </div>

            @if(!empty($sourceItem['category']))
                <div>
                    <label class="text-sm text-slate-600">Categoría del Excel</label>
                    <div class="mt-1 rounded-lg border bg-slate-50 px-3 py-2">
                        {{ $sourceItem['category'] }}
                    </div>

                    @switch($sourceItem['category_resolution']['status'] ?? null)
                        @case('found')
                            <p class="mt-1 text-sm text-emerald-700">Categoría encontrada.</p>
                            @break
                        @case('missing')
                            <p class="mt-1 text-sm text-amber-700">
                                La categoría pendiente se creará al autorizar este producto.
                            </p>
                            @break
                        @case('invalid')
                            <p class="mt-1 text-sm text-red-700">
                                Formato inválido. Use “Categoría” o “Categoría &gt; Subcategoría”.
                            </p>
                            @break
                    @endswitch
                </div>
            @endif

            @if(!empty($sourceItem['brand']))
                <div class="rounded-lg border bg-slate-50 p-3">
                    <span class="text-sm text-slate-500">Marca del Excel:</span>
                    {{ $sourceItem['brand'] }}
                </div>
            @endif

            @if(!empty($sourceItem['unit']))
                <div class="rounded-lg border bg-slate-50 p-3">
                    <span class="text-sm text-slate-500">Unidad del Excel:</span>
                    {{ $sourceItem['unit'] }}
                </div>
            @endif
        </div>

        <button
            @disabled(($sourceItem['category_resolution']['status'] ?? null) === 'invalid')
            class="mt-5 rounded-lg bg-amber-500 px-5 py-2 text-white disabled:cursor-not-allowed disabled:opacity-50">
            Guardar producto
        </button>
    </form>
</x-card>
@endsection
