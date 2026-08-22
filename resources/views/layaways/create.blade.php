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
                            <option value="{{$p->id}}">{{$p->name}} · Stock {{number_format((float)$p->branches->first()?->pivot->stock,4,',','.')}} · ₡{{number_format((float)$p->sale_price,0,',','.')}}</option>
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
<x-card><h2 class="mb-3 font-semibold">Abono inicial</h2><div class="grid gap-4 md:grid-cols-4"><div><label class="block text-sm">Monto *</label><input required type="number" min="1" step="1" name="initial_amount" class="w-full rounded border-slate-300"></div><div><label class="block text-sm">Forma de pago *</label><select required name="payment_method_id" class="w-full rounded border-slate-300">@foreach($methods as $m)<option value="{{$m->id}}">{{$m->name}}</option>@endforeach</select></div><div><label class="block text-sm">Sesión de caja</label><select name="cash_session_id" class="w-full rounded border-slate-300"><option value="">Seleccione</option>@foreach($sessions as $s)<option value="{{$s->id}}">{{$s->session_number}} — {{$s->cashRegister->name}}</option>@endforeach</select></div><div><label class="block text-sm">Referencia</label><input name="reference" class="w-full rounded border-slate-300"></div></div></x-card>
@if($errors->any())<p class="rounded bg-red-50 p-3 text-red-700">{{$errors->first()}}</p>@endif<button class="rounded-lg bg-amber-500 px-5 py-3 font-semibold text-white">Crear apartado</button></form>@endsection
