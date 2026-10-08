@extends('cobros.layout')

@section('title', 'Préstamo entregado — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <a href="{{ $backRoute ?? route('cobros.owner.creditos.show', $credit->id) }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Volver al préstamo</a>

    <section class="rounded-2xl border-2 border-primary bg-amber-50 p-5 text-center sm:p-6">
        <p class="text-lg font-extrabold text-slate-900">Préstamo entregado correctamente ✓</p>
        <p class="mt-2 text-sm text-slate-700">
            Cliente: <span class="font-semibold">{{ $customerName }}</span> ·
            Total a pagar: <span class="font-semibold">{{ \App\Support\Cobros\Money::human((string) $terms['total_due'], $company->currency) }}</span>
        </p>
        <p class="mt-1 text-xs text-slate-500">Referencia del préstamo: <span class="font-semibold text-slate-700">{{ $credit->publicReference() }}</span> · Referencia de aceptación: {{ $acceptance->reference }}</p>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
        <h2 class="text-sm font-bold uppercase text-slate-500">Comprobante del préstamo</h2>
        <p class="mt-2 text-sm text-slate-600">El comprobante incluye las condiciones aceptadas, el calendario completo y la firma del cliente.</p>

        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <a href="{{ $pdfRoute ?? route('cobros.owner.creditos.comprobante_pdf', $credit->id) }}"
               class="flex min-h-[48px] items-center justify-center rounded-xl bg-primary px-4 text-base font-bold text-white hover:bg-primary-hover">
                VER COMPROBANTE
            </a>
            <a href="{{ $pdfDownloadRoute ?? route('cobros.owner.creditos.comprobante_pdf', ['credit' => $credit->id, 'descargar' => 1]) }}"
               class="flex min-h-[48px] items-center justify-center rounded-xl border border-primary bg-white px-4 text-base font-bold text-primary hover:bg-amber-50">
                DESCARGAR PDF
            </a>
            <a href="{{ $backRoute ?? route('cobros.owner.creditos.show', $credit->id) }}"
               class="flex min-h-[48px] items-center justify-center rounded-xl border border-slate-300 bg-white px-4 text-base font-bold text-slate-700 hover:bg-slate-50">
                VOLVER
            </a>
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
        <h2 class="text-sm font-bold uppercase text-slate-500">Enviar por WhatsApp</h2>

        @if($whatsappUrl)
            <p class="mt-2 text-sm text-slate-600">
                WhatsApp abre el mensaje con las condiciones del préstamo. El PDF no se adjunta solo:
                descárguelo y adjúntelo en la conversación.
            </p>
            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <a href="{{ $pdfDownloadRoute ?? route('cobros.owner.creditos.comprobante_pdf', ['credit' => $credit->id, 'descargar' => 1]) }}"
                   class="flex min-h-[48px] items-center justify-center rounded-xl border border-primary bg-white px-4 text-base font-bold text-primary hover:bg-amber-50">
                    DESCARGAR PDF
                </a>
                <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer"
                   class="flex min-h-[48px] items-center justify-center rounded-xl bg-primary px-4 text-base font-bold text-white hover:bg-primary-hover">
                    ABRIR WHATSAPP
                </a>
            </div>
            <p class="mt-2 text-xs text-slate-500">Descargue el comprobante y adjúntelo en WhatsApp.</p>
        @else
            <p class="mt-2 text-sm text-slate-600">
                Este cliente no tiene teléfono registrado. Puede entregar el comprobante impreso o
                agregar el número después para enviarlo por WhatsApp.
            </p>
            <div class="mt-4">
                <a href="{{ $pdfDownloadRoute ?? route('cobros.owner.creditos.comprobante_pdf', ['credit' => $credit->id, 'descargar' => 1]) }}"
                   class="flex min-h-[48px] items-center justify-center rounded-xl border border-primary bg-white px-4 text-base font-bold text-primary hover:bg-amber-50">
                    DESCARGAR PDF
                </a>
            </div>
        @endif
    </section>
</div>
@endsection
