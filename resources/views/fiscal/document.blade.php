@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-2xl space-y-5 pb-24 md:pb-8">
    <section class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-7">
        <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Facturación Electrónica</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-950">Documento {{ $typeLabels[$document->document_type] ?? $document->document_type }}</h1>

        <dl class="mt-4 space-y-2 text-sm text-slate-700">
            <div class="flex justify-between gap-3"><dt>Estado</dt><dd class="font-bold text-right">{{ $statusLabels[$document->status] ?? $document->status }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Fecha</dt><dd class="font-bold text-right">{{ $document->created_at->format('d/m/Y H:i') }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Intento</dt><dd class="font-bold text-right">{{ $document->attempt_number }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Origen</dt><dd class="font-bold text-right">{{ $document->source_type ?: ('venta ' . ($document->sale_id ?: '—')) }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Estado de Hacienda</dt><dd class="font-bold text-right">{{ $statusLabels[$document->status] ?? $document->status }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Clave</dt><dd class="font-mono text-xs text-right break-all">{{ $document->clave ?: '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Consecutivo</dt><dd class="font-bold text-right">{{ $document->consecutivo ?: '—' }}</dd></div>
            @if($document->status === 'rejected' || $document->status === 'error')
                <div class="flex justify-between gap-3"><dt>Detalle</dt><dd class="text-right">{{ $document->last_error_code }}{{ $document->last_error_message ? ' — ' . \Illuminate\Support\Str::limit($document->last_error_message, 160) : '' }}</dd></div>
            @endif
            <div class="flex justify-between gap-3"><dt>Custodia</dt><dd class="font-bold text-right">{{ $custody ? 'Resguardado' : 'Sin registro' }}</dd></div>
        </dl>

        <a href="{{ route('fiscal.history') }}" class="mt-5 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Volver al historial</a>
    </section>
</div>
@endsection
