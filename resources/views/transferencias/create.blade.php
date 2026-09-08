@extends('layouts.app')

@section('content')
<div class="mx-auto min-w-0 max-w-5xl space-y-6" x-data="transferCreate({{ Illuminate\Support\Js::from($initialProducts) }}, {{ Illuminate\Support\Js::from(route('transferencias.products.search')) }})">
    <header>
        <a href="{{ route('transferencias.index') }}" class="mb-2 inline-flex min-h-11 items-center text-sm font-semibold text-slate-600 hover:text-slate-900">← Volver a traslados</a>
        <h1 class="text-xl font-bold text-slate-800 sm:text-2xl">Nuevo traslado</h1>
        <p class="mt-1 text-sm text-slate-500">Agregue los productos que enviará desde la sucursal activa.</p>
    </header>
    @include('transferencias._messages')
    <noscript><p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Active JavaScript para buscar y agregar productos.</p></noscript>
    @if(!$fromBranch || $branches->isEmpty())
        <p role="alert" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            {{ !$fromBranch ? 'Seleccione una sucursal activa para crear el traslado.' : 'No hay sucursales destino disponibles para su usuario.' }}
        </p>
    @endif
    <form action="{{ route('transferencias.store') }}" method="POST" class="space-y-6" @submit="submit($event)">
        @csrf
        <section class="grid min-w-0 gap-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5 md:grid-cols-2">
            <div class="min-w-0">
                <h2 class="mb-2 text-sm font-semibold text-slate-700">Sucursal origen</h2>
                <p class="break-words rounded-xl bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800">{{ $fromBranch?->name ?? 'Sin sucursal activa' }} @if($fromBranch?->code)<span class="text-slate-500">({{ $fromBranch->code }})</span>@endif</p>
                <p class="mt-2 text-xs text-slate-500">El origen corresponde a la sucursal activa.</p>
            </div>
            <div class="min-w-0">
                <label for="to_branch_id" class="mb-2 block text-sm font-semibold text-slate-700">Sucursal destino</label>
                <select name="to_branch_id" id="to_branch_id" required class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500" @disabled(!$fromBranch || $branches->isEmpty()) aria-invalid="{{ $errors->has('to_branch_id') ? 'true' : 'false' }}">
                    <option value="">Seleccione una sucursal</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((string) old('to_branch_id') === (string) $branch->id)>{{ $branch->name }} ({{ $branch->code }})</option>
                    @endforeach
                </select>
                @error('to_branch_id')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
        </section>
        <section class="min-w-0 space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold text-slate-800">Productos</h2>
                <span class="text-sm text-slate-500" x-text="lines.length + ' productos'">0 productos</span>
            </div>
            <div @keydown.escape="results = []; searched = false">
                <label for="transfer-product-search" class="mb-2 block text-sm font-medium text-slate-700">Buscar por nombre, código o código de barras</label>
                <div class="flex min-w-0 gap-2">
                    <input id="transfer-product-search" type="search" maxlength="100" autocomplete="off" x-model="query" @input="invalidateSearch()" @input.debounce.300ms="search()" @keydown.enter.prevent="search()" class="min-h-11 min-w-0 w-full rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500" placeholder="Buscar producto…" aria-controls="transfer-product-results">
                    <button type="button" @click="search()" class="min-h-11 shrink-0 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Buscar</button>
                </div>
                <p x-cloak x-show="loading" role="status" class="mt-2 text-sm text-slate-500">Buscando productos…</p>
                <p x-cloak x-show="searchError" x-text="searchError" role="alert" class="mt-2 text-sm text-red-700"></p>
                <div id="transfer-product-results" x-cloak x-show="results.length" class="mt-2 max-h-72 overflow-y-auto rounded-xl border border-slate-200">
                    <template x-for="product in results" :key="product.id">
                        <button type="button" @click="add(product)" class="flex min-h-11 w-full items-center justify-between gap-3 border-b border-slate-100 px-3 py-3 text-left hover:bg-amber-50 focus:bg-amber-50">
                            <span class="min-w-0"><span class="block break-words text-sm font-semibold text-slate-800" x-text="product.name"></span><span class="block break-words text-xs text-slate-500" x-text="(product.internal_code || 'Sin código') + ' · Disponible: ' + (product.branch_stock ?? '0')"></span></span>
                            <span class="shrink-0 text-sm font-semibold text-amber-800" x-text="lines.some(line => line.id === product.id) ? 'Agregado' : 'Agregar'"></span>
                        </button>
                    </template>
                </div>
                <p x-cloak x-show="searched && !loading && !results.length && !searchError" class="mt-2 text-sm text-slate-500">No se encontraron productos.</p>
                <p x-cloak x-show="notice" x-text="notice" role="status" class="mt-2 text-sm text-amber-800"></p>
            </div>
            <p x-show="!lines.length" class="rounded-xl bg-slate-50 p-5 text-center text-sm text-slate-500">Busque y agregue al menos un producto.</p>
            <div class="space-y-3">
                <template x-for="(line, index) in lines" :key="line.id">
                    <article class="grid min-w-0 gap-3 rounded-xl border border-slate-200 p-3 md:grid-cols-[minmax(0,1fr)_10rem_auto] md:items-center">
                        <div class="min-w-0">
                            <h3 class="break-words text-sm font-semibold text-slate-800" x-text="line.name"></h3>
                            <p class="break-words text-xs text-slate-500" x-text="line.internal_code || 'Sin código'"></p>
                            <p class="mt-1 text-xs text-slate-500" x-text="'Disponible en origen: ' + (line.branch_stock ?? '0')"></p>
                            <input type="hidden" :name="'products[' + index + '][product_id]'" :value="line.id">
                        </div>
                        <div>
                            <label :for="'quantity-' + line.id" class="mb-1 block text-sm font-medium text-slate-700">Cantidad</label>
                            <input :id="'quantity-' + line.id" :name="'products[' + index + '][quantity]'" type="number" inputmode="decimal" required :min="line.allows_decimals ? '0.0001' : '1'" :step="line.allows_decimals ? '0.0001' : '1'" :max="line.branch_stock ?? '0'" x-model="line.quantity" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2 text-right text-sm tabular-nums focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">
                        </div>
                        <button type="button" @click="remove(index)" :aria-label="'Quitar ' + line.name" class="min-h-11 rounded-xl border border-red-200 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Quitar</button>
                    </article>
                </template>
            </div>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <label for="notes" class="mb-2 block text-sm font-semibold text-slate-700">Observaciones <span class="font-normal text-slate-500">(opcional)</span></label>
            <textarea name="notes" id="notes" rows="3" maxlength="1000" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">{{ old('notes') }}</textarea>
        </section>
        <div class="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <a href="{{ route('transferencias.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700">Cancelar</a>
            <button type="submit" disabled @if($fromBranch && $branches->isNotEmpty()) :disabled="!lines.length || submitting" @endif class="min-h-11 rounded-xl bg-amber-500 px-5 py-3 text-sm font-semibold text-slate-950 hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-50" x-text="submitting ? 'Creando…' : 'Crear traslado'">Crear traslado</button>
        </div>
    </form>
</div>
@endsection
