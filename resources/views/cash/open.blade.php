@extends('layouts.app')
@section('title','Abrir caja')
@section('content')
<div class="mx-auto max-w-4xl" x-data="cashOpening()">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-semibold">Abrir caja</h2>
            <p class="text-sm text-slate-600">Registra el fondo inicial contado.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('cash.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 py-2">Volver</a>
@if($selectedRegisterId)
                <a href="{{ route('cash.drawer-receipt', 'opening') }}?cash_register_id={{ $selectedRegisterId }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-amber-400 px-4 py-2 text-sm font-medium text-slate-900 hover:bg-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/20" title="Imprime comprobante para abrir el cajón físicamente">
                    <svg class="h-5 w-5 fill-current" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2-2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2-2v4a2 2 0 002 2h6m-6-4V7"/></svg>
                    <span>Abrir cajón</span>
                </a>
            @else
                <button type="button" disabled class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-medium text-slate-400 cursor-not-allowed" title="Seleccione una caja física para poder abrir el cajón">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2-2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 002-2v4a2 2 0 002 2h6m-6-4V7"/></svg>
                    <span>Abrir cajón</span>
                </button>
            @endif
        </div>
    </div>
    @if($registers->isEmpty())<x-card><div class="py-8 text-center"><p class="text-lg font-semibold">No existen cajas activas en esta sucursal.</p>@if($canManageRegisters)<a href="{{ route('settings.cash-registers.index') }}" class="mt-5 inline-block rounded-xl bg-amber-500 px-5 py-3 font-semibold text-white">Configurar cajas</a>@endif</div></x-card>@else
    <x-card class="border-slate-400"><form method="POST" action="{{ route('cash.open.store') }}" @submit="processing=true">@csrf
    @if($errors->any())<div class="mb-5 rounded-lg bg-red-50 p-4 text-red-700">{{ $errors->first() }}</div>@endif
    <div class="space-y-6"><div><label class="mb-2 block font-medium">Caja física</label><select name="cash_register_id" required class="w-full rounded-xl border border-slate-300 px-4 py-3">@foreach($registers as $register)<option value="{{ $register->id }}" @selected((int)old('cash_register_id',$selectedRegisterId)===$register->id)>{{ $register->name }}{{ $register->is_default?' — Predeterminada':'' }}</option>@endforeach</select></div>
    @foreach(['bill'=>'Billetes','coin'=>'Monedas'] as $type=>$label)
    <section class="space-y-3"><h3 class="text-lg font-semibold">{{ $label }}</h3>
    @foreach($denominations->where('type',$type) as $denomination)
    <div class="grid grid-cols-2 items-center gap-3 sm:grid-cols-3">
    <label for="opening-denomination-{{ $denomination->id }}">{{ $denomination->label }}</label>
    <input id="opening-denomination-{{ $denomination->id }}" name="denominations[{{ $denomination->id }}]" x-model="quantities[{{ $denomination->id }}]" type="number" inputmode="numeric" min="0" max="1000000" step="1" autocomplete="off" required class="min-h-11 w-full min-w-0 rounded-xl border border-slate-400 px-3 py-2 text-right focus:border-amber-500 focus:ring-2 focus:ring-amber-500/20">
    <span class="col-span-2 text-right sm:col-span-1" x-text="money({{ (int)$denomination->value }} * (Number(quantities[{{ $denomination->id }}]) || 0))"></span>
    </div>
    @endforeach</section>
    @endforeach
    <div class="rounded-xl bg-slate-900 p-5 text-right text-white"><span>Fondo inicial CRC</span><strong class="block break-words text-3xl" x-text="money(total)"></strong></div>
    <p class="text-sm text-slate-500">Ingrese las cantidades contadas; use 0 cuando no tenga esa denominación.</p>@if($settings->accepts_usd)<div class="grid gap-5 sm:grid-cols-2"><div><label class="mb-2 block font-medium">Tipo de cambio: USD 1 = ₡</label><input name="usd_exchange_rate" type="number" min="0.0001" step="0.0001" required class="w-full rounded-xl border border-slate-300 px-4 py-3">@if($settings->usd_exchange_rate_min||$settings->usd_exchange_rate_max)<p class="mt-1 text-sm text-slate-500">Rango: {{ $settings->usd_exchange_rate_min??'sin mínimo' }} – {{ $settings->usd_exchange_rate_max??'sin máximo' }}</p>@endif</div><div><label class="mb-2 block font-medium">Fondo inicial en dólares</label><input name="opening_amount_usd" type="number" min="0" step="0.01" value="{{ old('opening_amount_usd','') }}" class="w-full rounded-xl border border-slate-300 px-4 py-3"></div></div><p class="text-sm text-slate-500">El tipo de cambio quedará fijo para esta sesión y será registrado por {{ $cashier->name }}.</p>@endif
    <label class="flex min-h-11 items-center gap-3"><input name="confirmation" value="1" type="checkbox" required class="mt-1 rounded text-amber-500"><span>Confirmo que el fondo inicial contado es correcto.</span></label></div>
    <div class="rounded-xl border border-slate-300 px-6 py-3 text-right"><a href="{{ route('cash.index') }}" class="inline-block rounded-xl border border-slate-300 px-6 py-3">Cancelar</a><button :disabled="processing" class="inline-block rounded-xl bg-amber-500 px-6 py-3 font-bold text-white hover:bg-amber-600 disabled:opacity-50" x-text="processing?'Abriendo…':'Abrir caja'"></button></div>
</form></x-card>@endif</div>
<script>
function cashOpening(){return {
    processing:false,
    quantities:@js($denominations->mapWithKeys(fn($d)=>[$d->id=>old("denominations.$d->id",'')])),
    values:@js($denominations->mapWithKeys(fn($d)=>[$d->id=>(int)$d->value])),
    get total(){return Object.entries(this.values).reduce((sum,[id,value])=>sum+value*(Number(this.quantities[id])||0),0)},
    money(value){return new Intl.NumberFormat('es-CR',{style:'currency',currency:'CRC',maximumFractionDigits:0}).format(value)}
}}
</script>
@endsection
