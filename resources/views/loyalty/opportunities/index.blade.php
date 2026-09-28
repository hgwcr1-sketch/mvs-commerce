@extends('layouts.app')
@php use Carbon\CarbonImmutable; @endphp
@section('title', 'Oportunidades de Fidelización')
@section('content')
<div class="space-y-6">
    <div><h1 class="text-2xl font-semibold text-slate-800">Oportunidades</h1><p class="text-sm text-slate-500">Cumpleaños e inactividad global de la empresa, sin duplicar rangos.</p></div>
    @if(session('success'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
    <form method="GET" class="grid grid-cols-1 items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3">
        <div><label class="block text-xs font-semibold uppercase text-slate-500">Tipo</label><select name="type" class="form-input mt-1 w-full"><option value="">Todas</option>@foreach(['birthday'=>'Cumpleaños hoy','inactive_30'=>'+30 días','inactive_60'=>'+60 días','inactive_90'=>'+90 días'] as $value=>$label)<option value="{{ $value }}" @selected(request('type')===$value)>{{ $label }}</option>@endforeach</select></div>
        <div><label class="block text-xs font-semibold uppercase text-slate-500">Contacto</label><select name="contacted" class="form-input mt-1 w-full"><option value="">Todos</option><option value="1" @selected(request('contacted')==='1')>Contactados</option><option value="0" @selected(request('contacted')==='0')>No contactados</option></select></div>
        <button class="min-h-11 w-full rounded-lg bg-slate-800 px-4 py-2.5 font-semibold text-white">Filtrar</button>
    </form>
    <div class="space-y-3 md:hidden">
    @forelse($customers as $customer)
        @php
            $message = $messages->message($company->id, $customer->opportunity_type, $customer, (int) $customer->days_inactive, null);
            $whatsappUrl = $messages->whatsappUrl($company, $customer, $message);
        @endphp
        <article data-mobile-opportunity-card class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0"><h2 class="break-words font-semibold text-slate-800">{{ $customer->name }}</h2><p class="mt-1 text-sm text-slate-500">{{ $customer->phone ? trim(($customer->phone_country_code ?? '').' '.$customer->phone) : 'Sin teléfono' }}</p></div>
                <span class="shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">{{ ['birthday'=>'Cumpleaños','inactive_30'=>'+30 días','inactive_60'=>'+60 días','inactive_90'=>'+90 días'][$customer->opportunity_type] }}</span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-slate-500">Última compra</dt><dd class="mt-1 font-medium text-slate-800">{{ $customer->last_qualifying_purchase_at ? CarbonImmutable::parse($customer->last_qualifying_purchase_at)->format('d/m/Y') : 'Sin compra' }}</dd></div>
                <div><dt class="text-slate-500">Días inactivo</dt><dd class="mt-1 font-medium text-slate-800">{{ $customer->days_inactive ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">Puntos</dt><dd class="mt-1 font-medium tabular-nums text-slate-800">{{ number_format((float) $customer->loyalty_balance, 2) }}</dd></div>
                <div><dt class="text-slate-500">Contacto</dt><dd class="mt-1 font-medium text-slate-800">{{ $customer->contacts_count ? 'Contactado' : 'No contactado' }}</dd></div>
            </dl>
            <div class="mt-4 grid gap-2">
                @can('fidelidad.whatsapp')
                    @if($whatsappUrl)<a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" title="{{ $message }}" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-emerald-600 px-3 py-2 font-semibold text-white">Abrir WhatsApp</a>@elseif(!$company->whatsapp_enabled)<span class="text-xs text-slate-500">WhatsApp desactivado</span>@endif
                @endcan
                @can('fidelidad.contactar')<form method="POST" action="{{ route('loyalty.opportunities.contact', $customer) }}">@csrf<input type="hidden" name="opportunity_type" value="{{ $customer->opportunity_type }}"><button class="min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 font-semibold">Marcar contactado</button></form>@endcan
            </div>
        </article>
    @empty
        <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-slate-500">No hay oportunidades con estos filtros.</p>
    @endforelse
    </div>
    <div data-desktop-opportunities class="hidden overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm md:block"><div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-100 text-left text-xs uppercase text-slate-600"><tr><th class="px-4 py-3">Cliente</th><th class="px-4 py-3">Teléfono</th><th class="px-4 py-3">Última compra</th><th class="px-4 py-3">Días</th><th class="px-4 py-3">Puntos</th><th class="px-4 py-3">Oportunidad</th><th class="px-4 py-3">Contacto</th><th class="px-4 py-3">Acciones</th></tr></thead><tbody class="divide-y divide-slate-100">
    @forelse($customers as $customer)
        @php
            $message = $messages->message($company->id, $customer->opportunity_type, $customer, (int) $customer->days_inactive, null);
            $whatsappUrl = $messages->whatsappUrl($company, $customer, $message);
        @endphp
        <tr><td class="px-4 py-3 font-semibold text-slate-800">{{ $customer->name }}</td><td class="px-4 py-3">{{ $customer->phone ? trim(($customer->phone_country_code ?? '').' '.$customer->phone) : 'Sin teléfono' }}</td><td class="px-4 py-3">{{ $customer->last_qualifying_purchase_at ? CarbonImmutable::parse($customer->last_qualifying_purchase_at)->format('d/m/Y') : 'Sin compra' }}</td><td class="px-4 py-3">{{ $customer->days_inactive ?: '—' }}</td><td class="px-4 py-3">{{ number_format((float) $customer->loyalty_balance, 2) }}</td><td class="px-4 py-3">{{ ['birthday'=>'Cumpleaños','inactive_30'=>'+30 días','inactive_60'=>'+60 días','inactive_90'=>'+90 días'][$customer->opportunity_type] }}</td><td class="px-4 py-3">{{ $customer->contacts_count ? 'Contactado' : 'No contactado' }}</td><td class="px-4 py-3"><div class="flex flex-wrap gap-2">
            @can('fidelidad.whatsapp')
                @if($whatsappUrl)<a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" title="{{ $message }}" class="inline-flex min-h-10 items-center rounded bg-emerald-600 px-3 py-1.5 font-semibold text-white">Abrir WhatsApp</a>@elseif(!$company->whatsapp_enabled)<span class="text-xs text-slate-500">WhatsApp desactivado</span>@endif
            @endcan
            @can('fidelidad.contactar')<form method="POST" action="{{ route('loyalty.opportunities.contact', $customer) }}">@csrf<input type="hidden" name="opportunity_type" value="{{ $customer->opportunity_type }}"><button class="min-h-10 rounded border border-slate-300 px-3 py-1.5 font-semibold">Marcar contactado</button></form>@endcan
        </div></td></tr>
    @empty<tr><td colspan="8" class="px-4 py-10 text-center text-slate-500">No hay oportunidades con estos filtros.</td></tr>@endforelse
    </tbody></table></div></div>{{ $customers->links() }}
</div>
@endsection
