@extends('layouts.app') @section('title','Detalle de apartado') @section('content')
@php($labels=['active'=>'Activo','paid'=>'Pagado','delivered'=>'Entregado','expired'=>'Vencido','cancelled'=>'Cancelado'])
<div class="space-y-5"><div class="flex flex-wrap justify-between gap-3"><div><h1 class="text-2xl font-bold">{{$layaway->number}}</h1><p class="text-sm text-slate-500">{{$layaway->customer->name}}</p></div><a href="{{route('apartados.index')}}" class="rounded border px-4 py-2">Volver</a></div>
<x-card><div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5"><div>Estado<br><strong>{{$labels[$layaway->status]}}</strong></div><div>Total<br><strong>₡{{number_format((float)$layaway->total,0,',','.')}}</strong></div><div>Abonado<br><strong>₡{{number_format((float)$layaway->paid_total,0,',','.')}}</strong></div><div>Saldo<br><strong>₡{{number_format((float)$layaway->balance_due,0,',','.')}}</strong></div><div>Vencimiento<br><strong>{{$layaway->expires_at->format('d/m/Y')}}</strong></div></div></x-card>
<x-card><h2 class="mb-3 font-semibold">Productos reservados</h2><div class="overflow-x-auto"><table class="w-full min-w-[600px] text-sm"><thead><tr><th class="p-2 text-left">Producto</th><th class="p-2 text-right">Cantidad</th><th class="p-2 text-right">Precio</th><th class="p-2 text-right">Total</th></tr></thead><tbody>@foreach($layaway->items as $i)<tr class="border-t"><td class="p-2">{{$i->description}}<br>@include('partials.product-variant', ['product' => $i->product])</td><td class="p-2 text-right">{{number_format((float)$i->quantity,$i->product?->unit?->allows_decimals?4:0,',','.')}}</td><td class="p-2 text-right">₡{{number_format((float)$i->unit_price,0,',','.')}}</td><td class="p-2 text-right">₡{{number_format((float)$i->total,0,',','.')}}</td></tr>@endforeach</tbody></table></div></x-card>
@can('apartados.abonar')@if($layaway->status==='active'&&!$layaway->expires_at->isBefore(today()))<x-card x-data="mvsMixedPayments()">
    <h2 class="mb-3 font-semibold">Registrar abono</h2>
    <p class="mb-3 text-sm text-slate-500">Puede dividir el abono entre varios medios de pago. La suma debe ser exacta y no superar el saldo.</p>
    <form method="POST" action="{{route('apartados.payments.store',$layaway)}}">@csrf
    <input type="hidden" name="cash_session_id" value="{{$sessions->first()?->id}}">
    <input type="hidden" name="amount" :value="total">
    <div class="space-y-3">
        <template x-for="(row, i) in rows" :key="i">
            <div class="grid gap-3 rounded-lg border border-slate-200 p-3 md:grid-cols-5">
                <div>
                    <label class="block text-sm">Forma de pago *</label>
                    <select required x-model="row.method" :name="`payments[${i}][payment_method_id]`" class="w-full rounded border-slate-300">
                        <option value="">Seleccione</option>
                        @foreach($methods as $m)<option value="{{$m->id}}">{{$m->name}}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm">Monto *</label>
                    <input required type="number" min="0.01" step="0.01" max="{{$layaway->balance_due}}" x-model="row.amount" :name="`payments[${i}][amount]`" class="w-full rounded border-slate-300">
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
        <p class="text-sm font-semibold">Total del abono: ₡<span x-text="total"></span> · Saldo: ₡{{number_format((float)$layaway->balance_due,2,',','.')}}</p>
    </div>
    <button class="mt-4 rounded bg-amber-500 px-4 py-3 font-semibold text-white">Registrar abono</button>
    </form>
</x-card>
@endif @endcan
<x-card><h2 class="mb-3 font-semibold">Historial de abonos</h2><div class="overflow-x-auto"><table class="w-full min-w-[650px] text-sm"><thead><tr><th class="p-2 text-left">Fecha</th><th class="p-2">Método</th><th class="p-2">Referencia</th><th class="p-2">Notas</th><th class="p-2">Usuario</th><th class="p-2 text-right">Monto</th></tr></thead><tbody>@foreach($layaway->payments as $p)<tr class="border-t"><td class="p-2">{{$p->paid_at->format('d/m/Y H:i')}}</td><td class="p-2 text-center">{{$p->paymentMethod->name}}</td><td class="p-2 text-center">{{$p->reference??'—'}}</td><td class="p-2 text-center">{{$p->notes??'—'}}</td><td class="p-2 text-center">{{$p->user->name}}</td><td class="p-2 text-right">₡{{number_format((float)$p->amount,2,',','.')}}</td></tr>@endforeach</tbody></table></div></x-card>
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
<div class="flex flex-wrap gap-3">@can('apartados.entregar')@if($layaway->status==='paid')<form method="POST" action="{{route('apartados.deliver',$layaway)}}">@csrf<button class="rounded bg-emerald-600 px-4 py-2 font-semibold text-white">Entregar y completar venta</button></form>@endif @endcan @can('apartados.cancelar')@if(in_array($layaway->status,['active','paid']))<form method="POST" action="{{route('apartados.cancel',$layaway)}}" class="flex gap-2">@csrf<input required minlength="3" name="reason" placeholder="Motivo de cancelación" class="rounded border-slate-300"><button class="rounded bg-red-600 px-4 py-2 font-semibold text-white">Cancelar</button></form>@endif @endcan @if($layaway->sale)<a href="{{route('ventas.show',$layaway->sale)}}" class="rounded bg-slate-900 px-4 py-2 text-white">Ver venta</a>@endif</div>
@if($errors->any())<p class="rounded bg-red-50 p-3 text-red-700">{{$errors->first()}}</p>@endif</div>@endsection
