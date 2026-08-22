@extends('layouts.app')
@section('title', 'Pedido interno '.$order->number)
@section('content')
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h1 class="text-2xl font-bold">{{ $order->number }}</h1><p>{{ $order->branch->name }} · {{ $order->requester->name }}</p></div>
        <div class="flex gap-2">
            @if($canPrepare && $hasPendingPreparation)<a href="{{ route('pedidos.preparar-compra', ['order_id' => $order->id]) }}" class="rounded bg-indigo-600 px-4 py-2 font-bold text-white">Preparar compra</a>@endif
            <a href="{{ route('pedidos.index') }}" class="rounded border px-4 py-2">Volver</a>
        </div>
    </div>
    @if(session('success'))<div class="rounded-xl bg-emerald-50 p-4 font-semibold text-emerald-700">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="rounded-xl bg-red-50 p-4 font-semibold text-red-700">{{ $errors->first() }}</div>@endif
    <x-card>
        <div class="grid gap-3 md:grid-cols-3">
            <p><strong>Estado:</strong> {{ $order->status_label }}</p><p><strong>Fecha:</strong> {{ $order->created_at->format('d/m/Y H:i') }}</p><p><strong>Solicitante:</strong> {{ $order->requester->name }}</p>
            @if($order->reviewed_at)<p><strong>Última revisión:</strong> {{ $order->reviewed_at->format('d/m/Y H:i') }}</p>@endif
            @if($order->reviewedBy)<p><strong>Revisado por:</strong> {{ $order->reviewedBy->name }}</p>@endif
        </div>
        @if($order->notes)<p class="mt-3"><strong>Observación:</strong> {{ $order->notes }}</p>@endif
    </x-card>
    <x-card><div class="overflow-x-auto"><table class="min-w-full text-sm">
        <thead><tr><th class="p-2 text-left">Producto</th><th class="p-2 text-right">Existencia al solicitar</th><th class="p-2 text-right">Cantidad solicitada</th><th class="p-2 text-right">Precio de venta</th><th class="p-2 text-right">Cantidad aprobada</th><th class="p-2 text-right">Ya asignada</th><th class="p-2 text-right">Pendiente de preparar</th><th class="p-2 text-left">Estado</th><th class="p-2 text-left">Observaciones</th><th class="p-2 text-left">Decisión</th></tr></thead>
        <tbody class="divide-y">
        @foreach($order->items as $item)
            @php
                $formatQuantity = fn($value) => $item->allows_decimals_snapshot ? rtrim(rtrim(number_format((float)$value, 4, ',', '.'), '0'), ',') : number_format((float)$value, 0, ',', '.');
                $canReview = $order->status === \App\Models\Order::STATUS_PENDING && $item->item_status === \App\Models\OrderItem::STATUS_PENDING;
                $hasActiveSuppliers = $item->product->productSuppliers->isNotEmpty();
                $excludedSupplierIds = $existingProductSupplierIds->get($item->product_id, collect());
                $associableSuppliers = $availableSuppliers->whereNotIn('id', $excludedSupplierIds);
            @endphp
            <tr class="align-top" x-data="orderSupplierAssociation({{ Illuminate\Support\Js::from(route('pedidos.items.suppliers.store', [$order, $item])) }})">
                <td class="p-2">{{ $item->description }}<br><small>{{ $item->internal_code }} · {{ $item->unit_code }}</small></td>
                <td class="p-2 text-right">{{ $item->stock_snapshot === null ? '—' : $formatQuantity($item->stock_snapshot) }}</td>
                <td class="p-2 text-right font-semibold">{{ $formatQuantity($item->requested_quantity) }}</td>
                <td class="p-2 text-right">₡{{ number_format((float)$item->sale_price_snapshot, 0, ',', '.') }}</td>
                <td class="p-2 text-right">{{ $formatQuantity($item->approved_quantity) }}</td>
                <td class="p-2 text-right">{{ $formatQuantity($item->allocated_quantity ?? 0) }}</td>
                <td class="p-2 text-right">{{ $formatQuantity(max(0, (float) $item->approved_quantity - (float) ($item->allocated_quantity ?? 0))) }}</td>
                <td class="p-2">{{ $item->status_label }}</td>
                <td class="p-2"><strong>Solicitante:</strong> {{ $item->request_note ?? '—' }}<br><strong>Revisión:</strong> {{ $item->review_note ?? '—' }}</td>
                <td class="min-w-64 p-2">
                    @if($canReview)
                        @if($canApprove || $canReject)
                        <form method="POST" action="{{ route('pedidos.items.review', [$order, $item]) }}" class="space-y-2">
                            @csrf @method('PATCH')
                            @if($canApprove)
                                <label class="block text-xs font-semibold">Cantidad aprobada<input type="number" name="approved_quantity" value="{{ $item->requested_quantity }}" min="{{ $item->allows_decimals_snapshot ? '0.0001' : '1' }}" max="{{ $item->requested_quantity }}" step="{{ $item->allows_decimals_snapshot ? '0.0001' : '1' }}" required class="mt-1 w-full rounded-lg border-slate-300 px-3 py-2"></label>
                                <label class="block text-xs font-semibold">Proveedor
                                    <select x-ref="supplierSelect" name="supplier_id" required class="mt-1 w-full rounded-lg border-slate-300 px-3 py-2">
                                        <option value="">{{ $hasActiveSuppliers ? 'Seleccione' : 'Sin proveedor asociado' }}</option>
                                        @foreach($item->product->productSuppliers as $productSupplier)
                                            <option value="{{ $productSupplier->supplier_id }}">{{ $productSupplier->supplier->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                @unless($hasActiveSuppliers)
                                <div x-show="!associated" class="space-y-2">
                                    <p class="rounded-lg bg-amber-50 p-2 text-xs font-semibold text-amber-800">Sin proveedor asociado</p>
                                    @if($canAssociateSuppliers)
                                        <button type="button" @click="open = !open" class="text-sm font-bold text-indigo-700">+ Asociar proveedor</button>
                                        <div x-show="open" x-cloak class="rounded-xl border border-indigo-200 bg-indigo-50 p-3">
                                            <div x-ref="associationFields" class="space-y-2">
                                                <label class="block text-xs font-semibold">Proveedor activo
                                                    <select name="supplier_id" required class="mt-1 w-full rounded-lg border-slate-300 px-3 py-2">
                                                        <option value="">Seleccione</option>
                                                        @foreach($associableSuppliers as $supplier)
                                                            <option value="{{ $supplier->id }}">{{ $supplier->commercial_name ?: $supplier->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <label class="block text-xs font-semibold">Código del producto con el proveedor
                                                    <input name="supplier_product_code" maxlength="100" class="mt-1 w-full rounded-lg border-slate-300 px-3 py-2">
                                                </label>
                                                @if($canManageSupplierCosts)
                                                    <label class="block text-xs font-semibold">Costo actual
                                                        <input type="number" name="current_cost" min="0" step="0.0001" class="mt-1 w-full rounded-lg border-slate-300 px-3 py-2">
                                                    </label>
                                                @endif
                                                <label class="block text-xs font-semibold">Notas
                                                    <textarea name="notes" maxlength="2000" rows="2" class="mt-1 w-full rounded-lg border-slate-300 px-3 py-2"></textarea>
                                                </label>
                                                <input type="hidden" name="is_active" value="1">
                                                <p x-show="error" x-text="error" class="text-xs font-semibold text-red-700"></p>
                                                <button type="button" @click="submit" :disabled="saving" class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white disabled:opacity-50" x-text="saving ? 'Asociando…' : 'Asociar y seleccionar'"></button>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                                @endunless
                            @else
                                <input type="hidden" name="approved_quantity" value="0">
                            @endif
                            <label class="block text-xs font-semibold">Observación de revisión<textarea name="review_note" maxlength="1000" rows="2" class="mt-1 w-full rounded-lg border-slate-300 px-3 py-2"></textarea></label>
                            <div class="flex flex-wrap gap-2">
                                @if($canApprove)<button type="submit" class="rounded-lg bg-emerald-600 px-3 py-2 font-bold text-white">Guardar aprobación</button>@endif
                                @if($canReject)<button type="submit" onclick="this.form.elements['approved_quantity'].value='0'; if (this.form.elements['supplier_id']) this.form.elements['supplier_id'].value=''" class="rounded-lg border border-red-300 px-3 py-2 font-bold text-red-700">Rechazar línea</button>@endif
                            </div>
                        </form>
                        @else<span class="text-slate-500">Solo lectura</span>@endif
                    @else
                        <span class="text-slate-500">
                            Revisada
                            @if($item->supplier)
                                · {{ $item->supplier->name }}
                            @endif
                        </span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div></x-card>
</div>
@endsection

@push('scripts')
<script>
window.orderSupplierAssociation = endpoint => ({
    open: false,
    saving: false,
    error: '',
    associated: false,
    async submit() {
        if (this.saving) return;
        this.saving = true;
        this.error = '';
        try {
            const body = new FormData();
            this.$refs.associationFields.querySelectorAll('[name]').forEach(field => body.append(field.name, field.value));
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body,
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.message || 'No fue posible asociar el proveedor.');
            this.$refs.supplierSelect.add(new Option(payload.supplier.name, payload.supplier.id, true, true));
            this.associated = true;
            this.open = false;
        } catch (error) {
            this.error = error.message || 'No fue posible asociar el proveedor.';
        } finally {
            this.saving = false;
        }
    },
});
</script>
@endpush
