@extends('layouts.app')
@section('title','Verificación de mercadería')
@section('content')
<div class="mx-auto max-w-6xl space-y-5" data-responsive="360 768 1280">
<header><p class="text-sm font-semibold text-indigo-600">Compras</p><h1 class="text-2xl font-bold text-slate-900">Verificaciones de mercadería</h1><p class="mt-1 text-sm text-slate-600">Tareas digitales pendientes y recepciones ya revisadas.</p></header>
<form method="GET" class="rounded-2xl border bg-white p-4"><select name="status" onchange="this.form.submit()" class="form-input w-full md:max-w-xs"><option value="">Todos los estados</option>@foreach(['pending'=>'Pendiente','in_review'=>'En revisión','conform'=>'Conforme','differences'=>'Con diferencias','closed'=>'Cerrada'] as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></form>
<div class="grid gap-3 md:grid-cols-2">@forelse($verifications as $verification)<a href="{{ route('purchase-verifications.show',$verification) }}" class="block min-h-11 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-indigo-400"><div class="flex items-start justify-between gap-3"><div><h2 class="font-semibold">Compra {{ $verification->purchase?->number }}</h2><p class="text-sm text-slate-600">{{ $verification->purchase?->supplier?->commercial_name ?: $verification->purchase?->supplier?->name }}</p><p class="mt-2 text-xs text-slate-500">Responsable: {{ $verification->assignee?->name }}</p></div><span class="rounded-full bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-700">{{ str_replace('_',' ',ucfirst($verification->status)) }}</span></div></a>@empty<p class="text-sm text-slate-500">No hay verificaciones para mostrar.</p>@endforelse</div>
{{ $verifications->links() }}
</div>
@endsection
