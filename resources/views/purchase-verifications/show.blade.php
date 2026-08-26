@extends('layouts.app')
@section('title','Revisión de mercadería')
@section('content')
@php($statusLabels=['pending'=>'Pendiente','in_review'=>'En revisión','conform'=>'Conforme','differences'=>'Con diferencias','closed'=>'Cerrada'])
<div class="mx-auto max-w-6xl space-y-5" data-responsive="360 768 1280">
<header class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"><div><p class="text-sm font-semibold text-indigo-600">Compra {{ $verification->purchase->number }}</p><h1 class="text-2xl font-bold">Verificación digital</h1><p class="text-sm text-slate-600">{{ $verification->purchase->supplier?->commercial_name ?: $verification->purchase->supplier?->name }}</p></div><span class="self-start rounded-full bg-indigo-100 px-3 py-2 text-sm font-bold text-indigo-800">{{ $statusLabels[$verification->status] }}</span></header>
@if(session('success'))<div class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
@if($errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif

<section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">@foreach(['Registró'=>$verification->creator,'Asignó'=>$verification->assigner,'Verificó'=>$verification->verifier,'Cerró'=>$verification->resolver] as $label=>$person)<div class="rounded-xl border bg-white p-3"><p class="text-xs uppercase text-slate-500">{{ $label }}</p><p class="font-semibold">{{ $person?->name ?: 'Pendiente' }}</p></div>@endforeach</section>

@if(in_array($verification->status,['pending','in_review']) && $verification->assigned_to===auth()->id())
@can('compras.recepcion.verificar')
@if($verification->status==='pending')<form method="POST" action="{{ route('purchase-verifications.start',$verification) }}">@csrf<button class="min-h-11 w-full rounded-xl bg-indigo-600 px-4 font-bold text-white md:w-auto">Iniciar revisión</button></form>@endif
<form method="POST" action="{{ route('purchase-verifications.verify',$verification) }}" class="space-y-3">@csrf @method('PUT')
<div class="space-y-3">@foreach($verification->items as $item)<article x-data="{received: {{ (float)old('lines.'.$item->id.'.received_quantity',$item->received_quantity ?? $item->expected_quantity) }}, expected: {{ (float)$item->expected_quantity }} }" class="rounded-2xl border bg-white p-4"><div class="flex items-start justify-between gap-3"><div><h2 class="font-semibold">{{ $item->product->name }}</h2><p class="text-xs text-slate-500">{{ $item->product->internal_code }}</p></div><span class="rounded-lg bg-slate-100 px-2 py-1 text-sm">Esperado: {{ (float)$item->expected_quantity }}</span></div><div class="mt-3 grid gap-3 sm:grid-cols-2"><label><span class="form-label">Cantidad recibida</span><input x-model.number="received" required type="number" inputmode="decimal" step="0.0001" min="0" name="lines[{{ $item->id }}][received_quantity]" class="form-input w-full text-right"></label><label><span class="form-label">Observación</span><input name="lines[{{ $item->id }}][observation]" maxlength="1000" value="{{ old('lines.'.$item->id.'.observation',$item->observation) }}" class="form-input w-full" placeholder="Opcional"></label></div><div class="mt-3 grid grid-cols-2 gap-2 text-sm"><p class="rounded-lg bg-red-50 p-2 text-red-700">Faltante: <strong x-text="Math.max(expected-received,0).toFixed(4)"></strong></p><p class="rounded-lg bg-amber-50 p-2 text-amber-700">Sobrante: <strong x-text="Math.max(received-expected,0).toFixed(4)"></strong></p></div><label class="mt-3 flex min-h-11 items-center gap-2 font-semibold"><input required type="checkbox" value="1" name="lines[{{ $item->id }}][confirmed]" class="h-6 w-6"> Línea revisada físicamente</label></article>@endforeach</div>
<button class="sticky bottom-20 min-h-11 w-full rounded-xl bg-emerald-600 px-4 font-bold text-white md:bottom-4">Confirmar revisión completa</button></form>
@endcan
@else
<section class="space-y-3">@foreach($verification->items as $item)<article class="rounded-2xl border bg-white p-4"><div class="flex justify-between gap-3"><div><h2 class="font-semibold">{{ $item->product->name }}</h2><p class="text-sm text-slate-500">Esperado {{ (float)$item->expected_quantity }} · Recibido {{ $item->received_quantity===null?'—':(float)$item->received_quantity }}</p></div>@if($item->difference!==null)<span class="font-bold {{ (float)$item->difference===0.0?'text-emerald-700':'text-red-700' }}">{{ (float)$item->difference>0?'+':'' }}{{ (float)$item->difference }}</span>@endif</div>@if($item->observation)<p class="mt-2 rounded-lg bg-slate-50 p-2 text-sm">{{ $item->observation }}</p>@endif</article>@endforeach</section>
@endif

@can('compras.recepcion.resolver')
@if(in_array($verification->status,['conform','differences']))<form method="POST" action="{{ route('purchase-verifications.close',$verification) }}" class="rounded-2xl border bg-white p-4">@csrf<label><span class="form-label">Resolución / cierre {{ $verification->status==='differences'?'(obligatoria)':'' }}</span><textarea name="resolution_notes" class="form-input w-full" rows="3"></textarea></label><button class="mt-3 min-h-11 w-full rounded-xl bg-slate-800 px-4 font-bold text-white md:w-auto">Cerrar verificación</button></form>@endif
@endcan

@if(in_array($verification->status,['conform','closed']))
@can('productos.etiquetas.imprimir')<form method="POST" action="{{ route('purchase-verifications.labels',$verification) }}">@csrf<button class="min-h-11 w-full rounded-xl bg-amber-500 px-4 font-bold text-slate-900 md:w-auto">Preparar etiquetas</button><p class="mt-1 text-xs text-slate-500">Solo productos marcados “Imprime etiqueta: Sí”; podrás revisar el lote antes de imprimir.</p></form>@endcan
@endif
</div>
@endsection
