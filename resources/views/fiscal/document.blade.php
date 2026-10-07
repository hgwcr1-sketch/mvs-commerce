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

    <section x-data="{ open: {{ $errors->has('recipient') ? 'true' : 'false' }}, choice: '{{ $receiverEmail ? 'registered' : 'other' }}' }"
             x-init="$watch('choice', function (v) { if (v === 'registered' && $refs.registered) { $refs.recipient.value = $refs.registered.value; } if (v === 'other') { $refs.recipient.value = ''; $refs.recipient.focus(); } })"
             class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-7">
        <h2 class="text-lg font-bold text-slate-950">Entrega al cliente</h2>

        @php
            $lastDelivery = $deliveries->first();
            $deliveredStatus = $lastDelivery ? ($deliveryStatusLabels[$lastDelivery->status] ?? $lastDelivery->status) : 'Sin envíos';
        @endphp

        <dl class="mt-4 space-y-2 text-sm text-slate-700">
            <div class="flex justify-between gap-3">
                <dt>Último envío</dt>
                <dd class="font-bold text-right {{ $lastDelivery && $lastDelivery->status === 'sent' ? 'text-green-700' : ($lastDelivery && $lastDelivery->status === 'failed' ? 'text-red-700' : '') }}">{{ $deliveredStatus }}</dd>
            </div>
            <div class="flex justify-between gap-3"><dt>Destinatario</dt><dd class="font-bold text-right break-all">{{ $lastDelivery?->recipient ?: '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Fecha y hora</dt><dd class="font-bold text-right">{{ $lastDelivery?->created_at?->format('d/m/Y H:i') ?: '—' }}</dd></div>
            <div class="flex justify-between gap-3">
                <dt>Origen</dt>
                <dd class="font-bold text-right">
                    @if($lastDelivery)
                        {{ $deliveryTypeLabels[$lastDelivery->delivery_type] ?? $lastDelivery->delivery_type }}
                        @if($lastDelivery->delivery_type === 'manual')
                            · {{ $lastDelivery->user?->name ?: 'Usuario' }}
                        @else
                            · Sistema
                        @endif
                    @else
                        —
                    @endif
                </dd>
            </div>
        </dl>

        @error('recipient')
            <p class="mt-3 rounded-xl bg-red-50 px-3 py-2 text-sm font-bold text-red-700" role="alert">{{ $message }}</p>
        @enderror

        @can('fiscal.editar')
            @if($document->status === 'accepted')
                <button type="button" x-on:click="open = true"
                        class="mt-4 inline-flex min-h-[44px] w-full items-center justify-center rounded-xl bg-primary px-4 text-sm font-bold text-white hover:bg-primary-hover sm:w-auto">
                    {{ $deliveries->isEmpty() ? 'Enviar comprobante' : 'Reenviar comprobante' }}
                </button>
            @endif
        @endcan

        <h3 class="mt-6 text-sm font-bold uppercase tracking-wide text-amber-700">Historial de entregas</h3>
        @forelse($deliveries as $delivery)
            <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-bold">{{ $delivery->created_at->format('d/m/Y H:i') }}</span>
                    <span class="rounded-full bg-white px-2 py-0.5 text-xs font-bold {{ $delivery->status === 'sent' ? 'text-green-700' : ($delivery->status === 'failed' ? 'text-red-700' : 'text-slate-600') }}">
                        {{ $deliveryStatusLabels[$delivery->status] ?? $delivery->status }}
                    </span>
                </div>
                <p class="mt-1 break-all"><span class="text-slate-500">Destinatario:</span> <strong>{{ $delivery->recipient ?: '—' }}</strong></p>
                <p class="mt-1">
                    <span class="text-slate-500">Origen:</span> {{ $deliveryTypeLabels[$delivery->delivery_type] ?? $delivery->delivery_type }}
                    <span class="text-slate-500">· Usuario:</span> {{ $delivery->delivery_type === 'manual' ? ($delivery->user?->name ?: '—') : 'Sistema' }}
                </p>
                <p class="mt-1 break-all"><span class="text-slate-500">Adjuntos:</span> {{ $delivery->attachments ? implode(', ', $delivery->attachments) : '—' }}</p>
                @if($delivery->status === 'failed' && $delivery->last_error)
                    <p class="mt-1 text-red-700"><span class="font-bold">Error:</span> {{ \Illuminate\Support\Str::limit((string) $delivery->last_error, 140) }}</p>
                @endif
            </div>
        @empty
            <p class="mt-3 text-sm text-slate-500">Sin entregas registradas para este comprobante.</p>
        @endforelse

        @can('fiscal.editar')
            @if($document->status === 'accepted')
                <button type="button" x-on:click="open = true"
                        class="mt-4 inline-flex min-h-[44px] w-full items-center justify-center rounded-xl bg-primary px-4 text-sm font-bold text-white hover:bg-primary-hover sm:w-auto">
                    {{ $deliveries->isEmpty() ? 'Enviar comprobante' : 'Reenviar comprobante' }}
                </button>

                <div x-cloak x-show="open" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-3 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="delivery-title" @keydown.escape.window="open = false">
                    <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-4 shadow-xl sm:p-5" @click.outside="open = false">
                        <h3 id="delivery-title" class="text-lg font-bold text-slate-950">Confirmar envío del comprobante</h3>
                        <p class="mt-1 text-sm text-slate-600">Confirme el destino. El comprobante no se vuelve a emitir.</p>

                        <form method="POST" action="{{ route('fiscal.documents.mail', $document) }}" class="mt-4 space-y-4"
                              onsubmit="return confirm('¿Enviar el comprobante al correo indicado?')">
                            @csrf

                            @if($receiverEmail)
                                <input type="hidden" x-ref="registered" value="{{ $receiverEmail }}">
                                <label class="flex min-h-[44px] cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-3 text-sm text-slate-700">
                                    <input type="radio" name="choice" value="registered" x-model="choice" class="h-5 w-5 accent-amber-600">
                                    <span>Correo registrado: <strong class="break-all">{{ $receiverEmail }}</strong></span>
                                </label>
                            @endif

                            <label class="flex min-h-[44px] cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-3 text-sm text-slate-700">
                                <input type="radio" name="choice" value="other" x-model="choice" class="h-5 w-5 accent-amber-600">
                                <span>Otro correo (solo para este envío)</span>
                            </label>

                            <div>
                                <label for="delivery-recipient" class="block text-sm font-bold text-slate-700">Correo destinatario</label>
                                <input x-ref="recipient" id="delivery-recipient" type="email" name="recipient" maxlength="150" required
                                       value="{{ old('recipient', $receiverEmail) }}"
                                       x-bind:readonly="choice === 'registered' && {{ $receiverEmail ? 'true' : 'false' }}"
                                       placeholder="cliente@correo.com"
                                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-amber-500 focus:outline-none">
                                @error('recipient')
                                    <p class="mt-1 text-sm font-bold text-red-700">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row-reverse">
                                <button type="submit" class="inline-flex min-h-[44px] flex-1 items-center justify-center rounded-xl bg-primary px-4 text-sm font-bold text-white hover:bg-primary-hover">Confirmar y enviar</button>
                                <button type="button" x-on:click="open = false" class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">Cancelar</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        @endcan
    </section>
</div>
@endsection
