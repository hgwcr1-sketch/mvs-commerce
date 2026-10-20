@extends('cobros.layout')

@section('title', 'Mis referidos')

@section('content')
<div class="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div><p class="text-xs font-bold uppercase tracking-widest text-primary">MVS CobrOS</p><h1 class="mt-2 text-2xl font-black text-slate-900">Mis referidos</h1><p class="mt-2 text-sm text-slate-600">Consulta comercial administrada por MVS Maestro. Las invitaciones laborales no aparecen aquí.</p></div>
    @if (!$data['available'])
        <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-950"><h2 class="font-bold">No disponible</h2><p class="mt-1 text-sm">Maestro todavía no está conectado para referidos. No se muestran datos inventados ni se calculan comisiones localmente.</p></section>
    @else
        @php $statusLabels = ['pending' => 'Pendiente', 'qualified' => 'Calificado', 'credited' => 'Acreditado', 'ineligible' => 'No elegible', 'reversed' => 'Revertido']; @endphp
        <section class="rounded-2xl border border-slate-200 bg-white p-5"><h2 class="font-bold">Enlace personal</h2><div class="mt-3 flex gap-2"><input readonly value="{{ $data['link'] }}" class="min-h-11 w-full rounded-xl border border-slate-300 px-3"><button type="button" class="min-h-11 rounded-xl bg-primary px-4 font-bold" onclick="navigator.clipboard?.writeText(this.previousElementSibling.value)">Copiar</button></div><a class="mt-3 inline-flex min-h-11 items-center rounded-xl border border-primary px-4 font-bold text-primary" href="https://wa.me/?text={{ rawurlencode($data['link']) }}">Compartir por WhatsApp</a></section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5"><h2 class="font-bold">Historial</h2><p class="mt-3 text-sm text-slate-600">Los estados y saldos provienen de Maestro.</p><div class="mt-4 space-y-3">@forelse($data['referrals'] as $referral)<div class="rounded-xl border border-slate-200 p-3 text-sm"><div class="flex flex-wrap justify-between gap-2"><span>Referido {{ $referral['id'] ?? '—' }}</span><span class="rounded-full bg-slate-100 px-2 py-1 font-semibold">{{ $statusLabels[$referral['status'] ?? ''] ?? 'Estado no reconocido' }}</span></div><p class="mt-2 text-slate-600">Comisión confirmada: {{ $referral['commission_amount'] ?? '—' }} {{ $referral['currency'] ?? '' }}</p></div>@empty<p class="text-sm text-slate-500">No hay referidos disponibles.</p>@endforelse</div></section>
        @if(is_array($data['credit'] ?? null))<section class="rounded-2xl border border-slate-200 bg-white p-5"><h2 class="font-bold">Saldo a favor</h2><dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3"><div><dt class="text-slate-500">Disponible</dt><dd class="font-bold">{{ $data['credit']['available'] ?? '—' }}</dd></div><div><dt class="text-slate-500">Reservado</dt><dd class="font-bold">{{ $data['credit']['reserved'] ?? '—' }}</dd></div><div><dt class="text-slate-500">Aplicado</dt><dd class="font-bold">{{ $data['credit']['applied'] ?? '—' }}</dd></div></dl></section>@endif
    @endif
</div>
@endsection
