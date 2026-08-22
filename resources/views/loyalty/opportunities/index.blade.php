@extends('layouts.app')
@php use Carbon\CarbonImmutable; @endphp
@section('title', 'Oportunidades de Fidelización')
@section('content')
<div class="space-y-6">
    <div><h1 class="text-2xl font-semibold text-slate-800">Oportunidades</h1><p class="text-sm text-slate-500">Cumpleaños e inactividad global de la empresa, sin duplicar rangos.</p></div>
    @if(session('success'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div><label class="block text-xs font-semibold uppercase text-slate-500">Tipo</label><select name="type" class="form-input mt-1"><option value="">Todas</option>@foreach(['birthday'=>'Cumpleaños hoy','inactive_30'=>'+30 días','inactive_60'=>'+60 días','inactive_90'=>'+90 días'] as $value=>$label)<option value="{{ $value }}" @selected(request('type')===$value)>{{ $label }}</option>@endforeach</select></div>
        <div><label class="block text-xs font-semibold uppercase text-slate-500">Contacto</label><select name="contacted" class="form-input mt-1"><option value="">Todos</option><option value="1" @selected(request('contacted')==='1')>Contactados</option><option value="0" @selected(request('contacted')==='0')>No contactados</option></select></div>
        <button class="rounded-lg bg-slate-800 px-4 py-2.5 font-semibold text-white">Filtrar</button>
    </form>
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"><div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-100 text-left text-xs uppercase text-slate-600"><tr><th class="px-4 py-3">Cliente</th><th class="px-4 py-3">Teléfono</th><th class="px-4 py-3">Última compra</th><th class="px-4 py-3">Días</th><th class="px-4 py-3">Puntos</th><th class="px-4 py-3">Oportunidad</th><th class="px-4 py-3">Contacto</th><th class="px-4 py-3">Acciones</th></tr></thead><tbody class="divide-y divide-slate-100">
    @forelse($customers as $customer)
        @php
            $message = $messages->message($company->id, $customer->opportunity_type, $customer, (int) $customer->days_inactive, null);
            $whatsappUrl = $messages->whatsappUrl($company, $customer, $message);
        @endphp
        <tr><td class="px-4 py-3 font-semibold text-slate-800">{{ $customer->name }}</td><td class="px-4 py-3">{{ $customer->phone ? trim(($customer->phone_country_code ?? '').' '.$customer->phone) : 'Sin teléfono' }}</td><td class="px-4 py-3">{{ $customer->last_qualifying_purchase_at ? CarbonImmutable::parse($customer->last_qualifying_purchase_at)->format('d/m/Y') : 'Sin compra' }}</td><td class="px-4 py-3">{{ $customer->days_inactive ?: '—' }}</td><td class="px-4 py-3">{{ number_format((float) $customer->loyalty_balance, 2) }}</td><td class="px-4 py-3">{{ ['birthday'=>'Cumpleaños','inactive_30'=>'+30 días','inactive_60'=>'+60 días','inactive_90'=>'+90 días'][$customer->opportunity_type] }}</td><td class="px-4 py-3">{{ $customer->contacts_count ? 'Contactado' : 'No contactado' }}</td><td class="px-4 py-3"><div class="flex flex-wrap gap-2">
            @can('fidelidad.whatsapp')
                @if($whatsappUrl)<a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" title="{{ $message }}" class="rounded bg-emerald-600 px-3 py-1.5 font-semibold text-white">Abrir WhatsApp</a>@elseif(!$company->whatsapp_enabled)<span class="text-xs text-slate-500">WhatsApp desactivado</span>@endif
            @endcan
            @can('fidelidad.contactar')<form method="POST" action="{{ route('loyalty.opportunities.contact', $customer) }}">@csrf<input type="hidden" name="opportunity_type" value="{{ $customer->opportunity_type }}"><button class="rounded border border-slate-300 px-3 py-1.5 font-semibold">Marcar contactado</button></form>@endcan
        </div></td></tr>
    @empty<tr><td colspan="8" class="px-4 py-10 text-center text-slate-500">No hay oportunidades con estos filtros.</td></tr>@endforelse
    </tbody></table></div></div>{{ $customers->links() }}
</div>
@endsection
