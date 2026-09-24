@extends('layouts.app') @section('title','Nuevo apartado') @section('content')
<form method="POST" action="{{route('apartados.store')}}" class="space-y-5">@csrf<div class="flex justify-between"><div><h1 class="text-2xl font-bold">Nuevo apartado</h1><p class="text-sm text-slate-500">El inventario se reservará al guardar.</p></div><a href="{{route('apartados.index')}}" class="rounded border px-4 py-2">Volver</a></div>
<x-card><div class="grid gap-4 md:grid-cols-3"><div><label class="block text-sm">Cliente *</label><select required name="customer_id" class="w-full rounded border-slate-300"><option value="">Seleccione</option>@foreach($customers as $c)<option value="{{$c->id}}">{{$c->name}}</option>@endforeach</select></div><div><label class="block text-sm">Vencimiento</label><input type="date" name="expires_at" value="{{today()->addDays($company->layaway_validity_days??30)->toDateString()}}" class="w-full rounded border-slate-300"></div><div><label class="block text-sm">Notas</label><input name="notes" class="w-full rounded border-slate-300"></div></div></x-card>
<x-card>
    <div
        x-data="{
            lines: [{ key: 0, productId: '', quantity: 1 }],
            nextKey: 1,
            fractionalIds: @js($products->filter(fn($p) => $p->unit?->allows_decimals)->pluck('id')->values()),
            addLine() {
                this.lines.push({ key: this.nextKey++, productId: '', quantity: 1 });
            },
            removeLine(index) {
                if (this.lines.length === 1) {
                    this.lines[0].productId = '';
                    this.lines[0].quantity = 1;
                    return;
                }

                this.lines.splice(index, 1);
            },
        }"
        class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">Productos</h2>
            <button
                type="button"
                @click="addLine()"
                class="rounded-lg border border-amber-500 px-4 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50">
                Agregar producto
            </button>
        </div>

        <div class="space-y-3">
            <template x-for="(line, index) in lines" :key="line.key">
                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem_auto]">
                    <select
                        x-model="line.productId"
                        :name="`items[${index}][product_id]`"
                        required
                        class="rounded border-slate-300">
                        <option value="">Producto</option>
                        @foreach($products as $p)
                            <option value="{{$p->id}}">{{$p->name}}@php($label = \App\Support\ProductVariantFormatter::label($p))@if($label) · {{ $label }}@endif · Stock {{number_format((float)$p->branches->first()?->pivot->stock,4,',','.')}} · ₡{{number_format((float)$p->sale_price,0,',','.')}}</option>
                        @endforeach
                    </select>

                    <input
                        type="number"
                        x-model.number="line.quantity"
                        :name="`items[${index}][quantity]`"
                        :min="fractionalIds.includes(Number(line.productId)) ? 0.0001 : 1"
                        :step="fractionalIds.includes(Number(line.productId)) ? 0.0001 : 1"
                        required
                        placeholder="Cantidad"
                        class="rounded border-slate-300">

                    <button
                        type="button"
                        @click="removeLine(index)"
                        class="rounded-lg border border-red-200 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50"
                        aria-label="Eliminar producto">
                        Eliminar
                    </button>
                </div>
            </template>
        </div>
    </div>
</x-card>
<x-card x-data="mvsMixedPayments()">
    <h2 class="mb-3 font-semibold">Abono inicial</h2>
    <p class="mb-3 text-sm text-slate-500">Puede dividir el abono entre varios medios de pago. La suma debe ser exacta.</p>
    <input type="hidden" name="initial_amount" :value="total">
    <div class="mb-3 grid gap-4 md:grid-cols-2">
        <div>
            <label class="block text-sm">Sesión de caja (para efectivo)</label>
            <select name="cash_session_id" class="w-full rounded border-slate-300"><option value="">Seleccione</option>@foreach($sessions as $s)<option value="{{$s->id}}">{{$s->session_number}} — {{$s->cashRegister->name}}</option>@endforeach</select>
        </div>
    </div>
    <div class="space-y-3">
        <template x-for="(row, i) in rows" :key="i">
            <div class="grid gap-3 rounded-lg border border-slate-200 p-3 md:grid-cols-5">
                <div>
                    <label class="block text-sm">Forma de pago *</label>
                    <select required x-model="row.method" :name="`payments[${i}][payment_method_id]`" class="w-full rounded border-slate-300">
                        <option value="">Seleccione</option>
                        @foreach($methods as $m)<option value="{{$m->id}}" @if($m->requires_reference) data-requires-reference="1" @endif @if($m->affects_cash) data-affects-cash="1" @endif>{{$m->name}}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm">Monto *</label>
                    <input required type="number" min="0.01" step="0.01" x-model="row.amount" :name="`payments[${i}][amount]`" class="w-full rounded border-slate-300">
                </div>
                <div>
                    <label class="block text-sm">Referencia</label>
                    <input x-model="row.reference" :name="`payments[${i}][reference]`" class="w-full rounded border-slate-300">
                </div>
                <div>
                    <label class="block text-sm">Notas</label>
                    <input x-model="row.notes" :name="`payments[${i}][notes]`" class="w-full rounded border-slate-300">
                </div>
                <div class="flex items-end">
                    <button type="button" @click="rows.splice(i, 1)" x-show="rows.length > 1" class="min-h-11 rounded border border-red-200 px-3 py-2 text-red-700">Quitar</button>
                </div>
            </div>
        </template>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-3">
        <button type="button" @click="rows.push({method:'',amount:'',reference:'',notes:''})" class="min-h-11 rounded border border-slate-300 px-4 py-2">+ Agregar medio</button>
        <p class="text-sm font-semibold">Total del abono: ₡<span x-text="total"></span></p>
    </div>
</x-card>
@if($errors->any())<p class="rounded bg-red-50 p-3 text-red-700">{{$errors->first()}}</p>@endif<button class="rounded-lg bg-amber-500 px-5 py-3 font-semibold text-white">Crear apartado</button></form>
<script>
function mvsMixedPayments() {
    return {
        rows: [{method:'',amount:'',reference:'',notes:''}],
        get total() {
            return this.rows.reduce((sum, row) => sum + (parseFloat(row.amount) || 0), 0).toFixed(2);
        }
    };
}
</script>
@endsection
