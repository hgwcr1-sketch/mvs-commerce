@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-3xl space-y-5 pb-24 md:pb-8">
    <section class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-7">
        <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Facturación Electrónica</p>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold text-slate-950">{{ $typeLabels[$document->document_type] ?? $document->document_type }}</h1>
            @if($document->status === 'accepted')
                <span class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-sm font-bold text-emerald-800">Aceptado</span>
            @elseif(in_array($document->status, ['rejected', 'error'], true))
                <span class="inline-flex rounded-full bg-rose-100 px-3 py-1 text-sm font-bold text-rose-800">Rechazado / Error</span>
            @else
                <span class="inline-flex rounded-full bg-sky-100 px-3 py-1 text-sm font-bold text-sky-800">{{ $statusLabels[$document->status] ?? $document->status }}</span>
            @endif
        </div>

        @if(session('status'))
            <div class="mt-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">{{ session('status') }}</div>
        @endif
        @if(session('download_notice'))
            <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">{{ session('download_notice') }}</div>
        @endif

        <div class="mt-5 grid grid-cols-1 gap-5 md:grid-cols-2">
            <div class="rounded-xl border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-amber-700">Emisor</p>
                <p class="mt-2 font-bold text-slate-900">{{ $company->trade_name }}</p>
                <p class="text-sm text-slate-600">{{ $company->legal_name ?? $company->trade_name }}</p>
                <p class="text-sm text-slate-600">{{ $company->identification ?? $company->tax_id ?? '—' }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-amber-700">Receptor</p>
                <p class="mt-2 font-bold text-slate-900">{{ $sale?->customer?->name ?? '—' }}</p>
                <p class="text-sm text-slate-600">{{ $sale?->customer?->identification ?? '—' }}</p>
                <p class="text-sm text-slate-600">{{ $sale?->customer?->email ?? '—' }}</p>
            </div>
        </div>

        <dl class="mt-5 space-y-2 text-sm text-slate-700">
            <div class="flex justify-between gap-3"><dt>Clave (50)</dt><dd class="font-mono text-xs text-right break-all">{{ $document->clave ?: '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Consecutivo (20)</dt><dd class="font-mono text-xs text-right break-all">{{ $document->consecutivo ?: '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>ID proveedor</dt><dd class="font-mono text-xs text-right break-all">{{ $document->provider_document_id ?: '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Venta POS relacionada</dt>
                <dd class="text-right">
                    @if($sale)
                        <a href="{{ route('pos.receipt', ['sale' => $sale->id]) }}" target="_blank" rel="noopener" class="font-bold text-amber-700 underline">{{ $sale->sale_number }}</a>
                    @else
                        <span class="font-bold">—</span>
                    @endif
                </dd>
            </div>
            <div class="flex justify-between gap-3"><dt>Fecha de emisión</dt><dd class="font-bold text-right">{{ $document->created_at->format('d/m/Y H:i') }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Estado MVS</dt><dd class="font-bold text-right">{{ $statusLabels[$document->status] ?? $document->status }}</dd></div>
            <div class="flex justify-between gap-3">
                <dt>Estado proveedor</dt>
                <dd class="font-bold text-right">{{ $document->provider_document_id ? ('Registrado · ' . $document->provider_document_id) : ($document->provider_request_id ? ('Petición · ' . $document->provider_request_id) : 'Sin consulta registrada') }}</dd>
            </div>
            <div class="flex justify-between gap-3"><dt>Estado Hacienda</dt><dd class="font-bold text-right">{{ $haciendaStatus ?: ($document->status === 'accepted' ? 'Aceptado (según MVS)' : 'Sin respuesta persistida') }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Intentos</dt><dd class="font-bold text-right">{{ $document->attempt_number ?? 0 }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Ambiente</dt><dd class="font-bold text-right">{{ $document->environment === 'production' ? 'Producción' : 'Pruebas' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Sucursal / Terminal</dt><dd class="font-bold text-right">{{ $branchName ?: '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Total</dt><dd class="font-bold text-right">{{ $sale ? '₡' . number_format((float) $sale->total, 2, ',', '.') : '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>IVA</dt><dd class="font-bold text-right">{{ $sale ? '₡' . number_format((float) $sale->tax_total, 2, ',', '.') : '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Moneda</dt><dd class="font-bold text-right">CRC — Colones</dd></div>
            @if($document->status === 'rejected' || $document->status === 'error')
                <div class="flex justify-between gap-3"><dt>Detalle</dt><dd class="text-right">{{ $document->last_error_code }}{{ $document->last_error_message ? ' — ' . \Illuminate\Support\Str::limit($document->last_error_message, 160) : '' }}</dd></div>
            @endif
            <div class="flex justify-between gap-3"><dt>Custodia</dt><dd class="font-bold text-right">{{ $custody ? 'Resguardado' : 'Sin registro' }}</dd></div>
        </dl>

        <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-amber-700">Acciones</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @if($sale)
                    <a href="{{ route('pos.receipt', ['sale' => $sale->id]) }}" target="_blank" rel="noopener" class="min-h-[44px] inline-flex items-center rounded-xl bg-slate-200 px-4 text-sm font-bold text-slate-800">Ver PDF</a>
                    <a href="{{ route('pos.receipt.pdf', ['sale' => $sale->id, 'format' => 'letter']) }}" class="min-h-[44px] inline-flex items-center rounded-xl bg-slate-800 px-4 text-sm font-bold text-white">Descargar PDF</a>
                @endif
                @if($xmlAvailable)
                    <a href="{{ route('fiscal.documents.xml', $document) }}" class="min-h-[44px] inline-flex items-center rounded-xl bg-slate-200 px-4 text-sm font-bold text-slate-800">Descargar XML enviado</a>
                    <a href="{{ route('fiscal.documents.xml', $document) }}" download class="min-h-[44px] inline-flex items-center rounded-xl bg-slate-200 px-4 text-sm font-bold text-slate-800">Descargar XML firmado</a>
                @else
                    <span class="inline-flex min-h-[44px] cursor-not-allowed items-center rounded-xl bg-slate-100 px-4 text-sm font-bold text-slate-400" title="El XML aún no está persistido en MVS">XML enviado: No disponible</span>
                    <span class="inline-flex min-h-[44px] cursor-not-allowed items-center rounded-xl bg-slate-100 px-4 text-sm font-bold text-slate-400" title="El XML firmado aún no está persistido en MVS">XML firmado: No disponible</span>
                @endif
                @if($responseAvailable)
                    <a href="{{ route('fiscal.documents.response', $document) }}" class="min-h-[44px] inline-flex items-center rounded-xl bg-slate-200 px-4 text-sm font-bold text-slate-800">Descargar respuesta Hacienda</a>
                @else
                    <span class="inline-flex min-h-[44px] cursor-not-allowed items-center rounded-xl bg-slate-100 px-4 text-sm font-bold text-slate-400" title="La respuesta de Hacienda aún no está persistida">Respuesta Hacienda: No disponible</span>
                @endif
                <form method="POST" action="{{ route('fiscal.documents.refresh', $document) }}" class="inline">
                    @csrf
                    <button type="submit" class="min-h-[44px] inline-flex items-center rounded-xl bg-[#D4AF37] px-4 text-sm font-bold text-black hover:bg-[#B1922D]" title="Solo consulta el estado en el proveedor: nunca reemite">Refrescar estado</button>
                </form>
            </div>
            <p class="mt-3 text-xs text-slate-500">"Refrescar estado" es una consulta de polling al proveedor. Nunca crea documentos ni repite emisiones.</p>
        </div>

        <a href="{{ route('fiscal.documents') }}" class="mt-5 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Volver a documentos</a>
    </section>
</div>
@endsection
