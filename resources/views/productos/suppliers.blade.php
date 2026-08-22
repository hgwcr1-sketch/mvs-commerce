@extends('layouts.app')

@section('title', 'Proveedores de '.$product->name)

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Proveedores del producto</h1>
            <p class="text-slate-600">{{ $product->internal_code }} · {{ $product->name }}</p>
        </div>
        <a href="{{ route('productos.index') }}" class="rounded-lg border px-4 py-2">Volver a productos</a>
    </div>

    @if(session('success'))
        <div class="rounded-xl bg-emerald-50 p-4 font-semibold text-emerald-700">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-xl bg-red-50 p-4 font-semibold text-red-700">{{ $errors->first() }}</div>
    @endif

    @if($canEdit)
    <x-card>
        <h2 class="mb-4 text-lg font-semibold">Asociar proveedor</h2>
        <form method="POST" action="{{ route('productos.proveedores.store', $product) }}" class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @csrf
            <label class="block text-sm font-semibold">Proveedor activo
                <select name="supplier_id" required class="mt-1 w-full rounded-lg border-slate-300">
                    <option value="">Seleccione</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->commercial_name ?: $supplier->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-semibold">Código del proveedor
                <input name="supplier_product_code" value="{{ old('supplier_product_code') }}" maxlength="100" class="mt-1 w-full rounded-lg border-slate-300">
            </label>
            @if($canManageCosts)<label class="block text-sm font-semibold">Costo actual
                <input type="number" name="current_cost" value="{{ old('current_cost') }}" min="0" step="0.0001" class="mt-1 w-full rounded-lg border-slate-300">
            </label>@endif
            <label class="flex items-center gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Relación activa</label>
            <label class="flex items-center gap-2"><input type="hidden" name="is_primary" value="0"><input type="checkbox" name="is_primary" value="1"> Proveedor principal</label>
            <label class="block text-sm font-semibold md:col-span-2 lg:col-span-3">Notas
                <textarea name="notes" maxlength="2000" rows="2" class="mt-1 w-full rounded-lg border-slate-300">{{ old('notes') }}</textarea>
            </label>
            <div><button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 font-bold text-white">Agregar proveedor</button></div>
        </form>
    </x-card>
    @endif

    <x-card>
        <h2 class="mb-4 text-lg font-semibold">Proveedores asociados</h2>
        <div class="space-y-4">
            @forelse($relations as $relation)
                <div class="rounded-xl border p-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <strong>{{ $relation->supplier->commercial_name ?: $relation->supplier->name }}</strong>
                            @if($relation->is_primary && $relation->is_active)<span class="ml-2 rounded-full bg-emerald-100 px-2 py-1 text-xs text-emerald-700">Principal</span>@endif
                            @unless($relation->is_active)<span class="ml-2 rounded-full bg-slate-200 px-2 py-1 text-xs">Relación inactiva</span>@endunless
                        </div>
                    </div>
                    @if($canEdit)
                        <form method="POST" action="{{ route('productos.proveedores.update', [$product, $relation]) }}" class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                            @csrf @method('PUT')
                            <label class="text-sm font-semibold">Código del proveedor<input name="supplier_product_code" value="{{ $relation->supplier_product_code }}" maxlength="100" class="mt-1 w-full rounded-lg border-slate-300"></label>
                            @if($canManageCosts)<label class="text-sm font-semibold">Costo actual<input type="number" name="current_cost" value="{{ $relation->current_cost }}" min="0" step="0.0001" class="mt-1 w-full rounded-lg border-slate-300"></label>@endif
                            <label class="text-sm font-semibold">Notas<textarea name="notes" maxlength="2000" rows="2" class="mt-1 w-full rounded-lg border-slate-300">{{ $relation->notes }}</textarea></label>
                            <label class="flex items-center gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($relation->is_active)> Relación activa</label>
                            <label class="flex items-center gap-2"><input type="hidden" name="is_primary" value="0"><input type="checkbox" name="is_primary" value="1" @checked($relation->is_primary)> Proveedor principal</label>
                            <div><button type="submit" class="rounded-lg bg-amber-500 px-4 py-2 font-bold text-white">Guardar cambios</button></div>
                        </form>
                        <form method="POST" action="{{ route('productos.proveedores.destroy', [$product, $relation]) }}" class="mt-3" onsubmit="return confirm('¿Eliminar esta relación?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-sm font-semibold text-red-700">Eliminar relación</button>
                        </form>
                    @else
                        <dl class="grid gap-2 text-sm md:grid-cols-3">
                            <div><dt class="font-semibold">Código</dt><dd>{{ $relation->supplier_product_code ?? '—' }}</dd></div>
                            @if($canManageCosts)<div><dt class="font-semibold">Costo actual</dt><dd>{{ $relation->current_cost === null ? '—' : number_format((float) $relation->current_cost, 4, ',', '.') }}</dd></div>@endif
                            <div><dt class="font-semibold">Notas</dt><dd>{{ $relation->notes ?? '—' }}</dd></div>
                        </dl>
                    @endif
                </div>
            @empty
                <p class="text-slate-500">Este producto no tiene proveedores asociados.</p>
            @endforelse
        </div>
    </x-card>
</div>
@endsection
