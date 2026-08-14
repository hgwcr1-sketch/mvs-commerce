@extends('layouts.app')
@section('title', 'Cierre de Caja')
@section('content')
<div class="mx-auto max-w-5xl space-y-6" x-data="cashClosing()">
    <div class="flex items-start justify-between gap-4">
        <div><h2 class="text-2xl font-semibold text-slate-800">Conteo de cierre</h2><p class="text-sm text-slate-600">{{ $cashSession->session_number }} — {{ $cashSession->cashRegister->name }}</p></div>
        <a href="{{ route('cash.index') }}" class="rounded-lg border border-slate-300 px-4 py-2">Volver</a>
    </div>
    @if($errors->any())<div class="rounded-lg bg-red-50 p-4 text-red-700">{{ $errors->first() }}</div>@endif
    @if($blind)<div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">Cierre ciego activo. Registre únicamente lo contado; los valores de control se calcularán después de confirmar.</div>@endif
    <form x-ref="closingForm" method="POST" action="{{ route('cash.closing.submit', $cashSession) }}" autocomplete="off" class="space-y-6" @submit.prevent="requestConfirmation">
        @csrf
        <input type="hidden" name="request_token" value="{{ old('request_token', $requestToken) }}">
        <x-card>
            <x-slot:header><h3 class="text-lg font-semibold">Billetes</h3></x-slot:header>
            <div class="space-y-3">@foreach($denominations->where('type','bill') as $denomination)<div class="grid grid-cols-[1fr_7rem_9rem] items-center gap-3"><label for="denomination-{{ $denomination->id }}">{{ $denomination->label }}</label><input id="denomination-{{ $denomination->id }}" name="denominations[{{ $denomination->id }}]" x-model.number="quantities[{{ $denomination->id }}]" type="number" min="0" step="1" autocomplete="off" required class="rounded-xl border-slate-300 px-3 py-2 text-right"><span class="text-right" x-text="money({{ (float)$denomination->value }}*(Number(quantities[{{ $denomination->id }}])||0))"></span></div>@endforeach</div>
        </x-card>
        <x-card>
            <x-slot:header><h3 class="text-lg font-semibold">Monedas</h3></x-slot:header>
            <div class="space-y-3">@foreach($denominations->where('type','coin') as $denomination)<div class="grid grid-cols-[1fr_7rem_9rem] items-center gap-3"><label for="denomination-{{ $denomination->id }}">{{ $denomination->label }}</label><input id="denomination-{{ $denomination->id }}" name="denominations[{{ $denomination->id }}]" x-model.number="quantities[{{ $denomination->id }}]" type="number" min="0" step="1" autocomplete="off" required class="rounded-xl border-slate-300 px-3 py-2 text-right"><span class="text-right" x-text="money({{ (float)$denomination->value }}*(Number(quantities[{{ $denomination->id }}])||0))"></span></div>@endforeach</div>
            <div class="mt-5 border-t pt-4 text-right"><span class="text-sm text-slate-500">Total de efectivo contado</span><strong class="block text-3xl" x-text="money(cashTotal)"></strong></div>
            @unless($blind)<div class="mt-3 text-right text-sm text-slate-600">Esperado: ₡{{ number_format($expectedCash,0,',','.') }}</div>@endunless
        </x-card>
        <x-card>
            <x-slot:header><h3 class="text-lg font-semibold">Otras formas de pago</h3></x-slot:header>
            <div class="space-y-5">@forelse($methods as $method)<div class="rounded-xl border border-slate-200 p-4"><div class="grid gap-4 sm:grid-cols-3"><div><label class="mb-2 block font-medium" for="payment-{{ $method->id }}">{{ $method->name }}</label><input id="payment-{{ $method->id }}" name="payments[{{ $method->id }}][reported_amount]" x-model.number="reportedPayments[{{ $method->id }}]" type="number" min="0" step="1" autocomplete="off" required value="{{ old("payments.$method->id.reported_amount",0) }}" class="w-full rounded-xl border-slate-300 px-4 py-3 text-right"></div><div><label class="mb-2 block text-sm">Referencia</label><input name="payments[{{ $method->id }}][reference]" maxlength="150" value="{{ old("payments.$method->id.reference") }}" class="w-full rounded-xl border-slate-300 px-4 py-3"></div><div><label class="mb-2 block text-sm">Notas</label><input name="payments[{{ $method->id }}][notes]" maxlength="5000" value="{{ old("payments.$method->id.notes") }}" class="w-full rounded-xl border-slate-300 px-4 py-3"></div></div>@unless($blind)<p class="mt-2 text-sm text-slate-600">Esperado: ₡{{ number_format((float)$expectedMethods->get($method->id,0),0,',','.') }}</p>@endunless</div>@empty<p class="text-slate-500">No hay otras formas de pago configuradas.</p>@endforelse</div>
        </x-card>
        <x-card><label class="mb-2 block font-medium" for="closing_notes">Notas del cierre <span class="font-normal text-slate-500">(opcional)</span></label><textarea id="closing_notes" name="closing_notes" x-model="closingNotes" rows="3" maxlength="5000" class="w-full rounded-xl border-slate-300 px-4 py-3">{{ old('closing_notes') }}</textarea></x-card>
        <div class="flex flex-wrap justify-end gap-3"><button type="submit" form="cancel-closing" class="rounded-xl border border-slate-300 px-5 py-3">Cancelar cierre</button><button type="submit" :disabled="processing" class="rounded-xl bg-amber-500 px-6 py-3 font-normal text-black hover:bg-amber-600 disabled:opacity-50">Revisar y confirmar</button></div>
    </form>
    <form id="cancel-closing" method="POST" action="{{ route('cash.closing.cancel',$cashSession) }}">@csrf</form>

    <div x-cloak x-show="confirmationOpen" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog" aria-modal="true" aria-labelledby="closing-confirmation-title" @keydown.escape.window="if (!processing) confirmationOpen=false" @keydown.enter.window="if (confirmationOpen) { $event.preventDefault(); confirmSubmit() }">
        <div class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl" @click.outside="if (!processing) confirmationOpen=false">
            <h3 id="closing-confirmation-title" class="text-xl font-semibold text-slate-900">Revise el conteo declarado</h3>
            <p class="mt-1 text-sm text-slate-600">Confirme que estos son los valores que contó antes de cerrar la caja.</p>

            <div class="mt-5 rounded-xl bg-slate-50 p-4">
                <span class="text-sm text-slate-600">Total de efectivo contado</span>
                <strong class="block text-3xl text-slate-900" x-text="money(cashTotal)"></strong>
            </div>

            <div class="mt-5 space-y-2">
                <h4 class="font-semibold text-slate-900">Denominaciones declaradas</h4>
                <template x-for="denomination in positiveDenominations" :key="denomination.id">
                    <p class="flex justify-between gap-4 text-sm"><span x-text="`${denomination.quantity} × ${money(denomination.value)}`"></span><span x-text="money(denomination.quantity * denomination.value)"></span></p>
                </template>
                <p x-show="positiveDenominations.length === 0" class="text-sm text-slate-500">No se declararon billetes ni monedas.</p>
            </div>

            <div class="mt-5 space-y-2">
                <h4 class="font-semibold text-slate-900">Formas de pago declaradas</h4>
                @forelse($methods as $method)
                    <p class="flex justify-between gap-4 text-sm"><span>{{ $method->name }}</span><span x-text="money(Number(reportedPayments[{{ $method->id }}]) || 0)"></span></p>
                @empty
                    <p class="text-sm text-slate-500">No hay otras formas de pago configuradas.</p>
                @endforelse
            </div>

            <div x-show="closingNotes.trim() !== ''" class="mt-5">
                <h4 class="font-semibold text-slate-900">Notas del cierre</h4>
                <p class="mt-1 whitespace-pre-wrap text-sm text-slate-700" x-text="closingNotes"></p>
            </div>

            <div class="mt-6 flex flex-wrap justify-end gap-3">
                <button type="button" :disabled="processing" @click="confirmationOpen=false" class="rounded-xl border border-slate-300 px-5 py-3 disabled:opacity-50">Volver a revisar</button>
                <button type="button" :disabled="processing" @click="confirmSubmit()" class="rounded-xl bg-amber-500 px-6 py-3 font-normal text-black hover:bg-amber-600 disabled:opacity-50" x-text="processing?'Enviando…':'Confirmar cierre'"></button>
            </div>
        </div>
    </div>
</div>
<script>
function cashClosing(){return{processing:false,confirmationOpen:false,quantities:@js($denominations->mapWithKeys(fn($d)=>[$d->id=>(int)old("denominations.$d->id",0)])),values:@js($denominations->mapWithKeys(fn($d)=>[$d->id=>(float)$d->value])),labels:@js($denominations->mapWithKeys(fn($d)=>[$d->id=>$d->label])),reportedPayments:@js($methods->mapWithKeys(fn($m)=>[$m->id=>(int)old("payments.$m->id.reported_amount",0)])),closingNotes:@js((string)old('closing_notes','')),money(value){return new Intl.NumberFormat('es-CR',{style:'currency',currency:'CRC',maximumFractionDigits:0}).format(Number(value)||0)},get cashTotal(){return Object.entries(this.values).reduce((sum,[id,value])=>sum+value*(Number(this.quantities[id])||0),0)},get positiveDenominations(){return Object.entries(this.values).map(([id,value])=>({id,quantity:Number(this.quantities[id])||0,value,label:this.labels[id]})).filter(item=>item.quantity>0)},requestConfirmation(){if(this.processing)return;this.confirmationOpen=true},confirmSubmit(){if(this.processing)return;this.processing=true;this.$nextTick(()=>this.$refs.closingForm.submit())}}}
</script>
@endsection
