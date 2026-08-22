@extends('layouts.app')
@section('title', 'Preparar pedidos a proveedor')
@section('content')
<div class="space-y-5">
    <div><h1 class="text-2xl font-bold">Preparar compra</h1><p class="text-slate-600">Seleccione cantidades aprobadas para consolidarlas por proveedor.</p></div>
    @if($errors->any())<div class="rounded-xl bg-red-50 p-4 font-semibold text-red-700">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('pedidos.preparar-compra.store') }}" class="space-y-4">
        @csrf
        <x-card><div class="overflow-x-auto"><table class="min-w-full text-sm">
            <thead><tr><th class="p-2">Seleccionar</th><th class="p-2 text-left">Pedido</th><th class="p-2 text-left">Producto</th><th class="p-2 text-left">Proveedor</th><th class="p-2 text-left">Código proveedor</th>@if($canViewCosts)<th class="p-2 text-right">Costo actual</th>@endif<th class="p-2 text-right">Disponible</th><th class="p-2 text-right">Cantidad</th></tr></thead>
            <tbody class="divide-y">
            @forelse($lines as $index => $line)
                @php($relation = $line->product->productSuppliers->firstWhere('supplier_id', $line->supplier_id))
                @php($available = (float) $line->approved_quantity - (float) ($line->allocated_quantity ?? 0))
                <tr><td class="p-2 text-center"><input type="checkbox" onchange="this.closest('tr').querySelector('[name*=allocated_quantity]').disabled=!this.checked; this.closest('tr').querySelector('[name*=order_item_id]').disabled=!this.checked"></td>
                    <td class="p-2">{{ $line->order->number }}</td><td class="p-2">{{ $line->description }}</td><td class="p-2">{{ $line->supplier->name }}</td><td class="p-2">{{ $relation?->supplier_product_code ?? '—' }}</td>
                    @if($canViewCosts)<td class="p-2 text-right">{{ $relation?->current_cost === null ? '—' : number_format((float) $relation->current_cost, 4, ',', '.') }}</td>@endif
                    <td class="p-2 text-right">{{ number_format($available, 4, ',', '.') }}</td>
                    <td class="p-2"><input disabled type="hidden" name="lines[{{ $index }}][order_item_id]" value="{{ $line->id }}"><input disabled type="number" name="lines[{{ $index }}][allocated_quantity]" value="{{ $available }}" min="{{ $line->allows_decimals_snapshot ? '0.0001' : '1' }}" max="{{ $available }}" step="{{ $line->allows_decimals_snapshot ? '0.0001' : '1' }}" class="w-28 rounded border-slate-300"></td></tr>
            @empty<tr><td colspan="8" class="p-8 text-center text-slate-500">No hay cantidades pendientes de preparar.</td></tr>@endforelse
            </tbody>
        </table></div></x-card>
        @if($lines->isNotEmpty())<label class="block font-semibold">Notas<textarea name="notes" rows="2" maxlength="2000" class="mt-1 w-full rounded border-slate-300"></textarea></label><button class="rounded bg-indigo-600 px-4 py-2 font-bold text-white">Crear pedidos a proveedor</button>@endif
    </form>
</div>
@endsection
