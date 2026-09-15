@extends('layouts.app')

@section('title', 'Centro de Control')

@section('content')
<div class="mx-auto max-w-7xl space-y-6" x-data="{ tab: '{{ $data['summary']['shortage_count'] > 0 ? 'transfers' : 'customers' }}', search: '' }">

    {{-- HEADER --}}
    <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-bold text-slate-800 sm:text-2xl">Centro de Control</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ $data['branch_name'] }} &mdash; Actualizado: {{ $data['generated_at'] }}
            </p>
        </div>
        <a href="{{ route('control-center.index') }}"
            class="inline-flex min-h-[44px] items-center gap-2 self-start rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 hover:border-slate-400">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182"/>
            </svg>
            Actualizar recomendaciones
        </a>
    </header>

    {{-- EMPTY STATE (solo inventario) --}}
    @if($data['summary']['shortage_count'] === 0 && empty($data['customers']))
        <div class="rounded-2xl border border-green-200 bg-green-50 p-6 text-center">
            <p class="text-lg font-semibold text-green-800">Todo en orden</p>
            <p class="mt-1 text-sm text-green-700">No hay productos por debajo del mínimo ni clientes nuevos que revisar.</p>
        </div>
    @endif

    {{-- A. NECESITA ACCIÓN (inventario) --}}
    @if($data['summary']['shortage_count'] > 0)
    <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:p-5">
        <h2 class="text-sm font-bold text-amber-900 uppercase tracking-wide">Inventario — Necesita acción</h2>
        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl bg-white p-3 text-center shadow-sm">
                <p class="text-2xl font-bold text-slate-800">{{ $data['summary']['shortage_count'] }}</p>
                <p class="text-xs text-slate-500">Productos bajo mínimo</p>
            </div>
            <div class="rounded-xl bg-white p-3 text-center shadow-sm">
                <p class="text-2xl font-bold text-blue-700">{{ $data['summary']['transfer_count'] }}</p>
                <p class="text-xs text-slate-500">Traslados sugeridos</p>
            </div>
            <div class="rounded-xl bg-white p-3 text-center shadow-sm">
                <p class="text-2xl font-bold text-amber-700">{{ $data['summary']['purchase_count'] }}</p>
                <p class="text-xs text-slate-500">Compras sugeridas</p>
            </div>
            <div class="rounded-xl bg-white p-3 text-center shadow-sm">
                <p class="text-2xl font-bold text-slate-500">{{ $data['summary']['group_count'] }}</p>
                <p class="text-xs text-slate-500">Grupos de compra</p>
            </div>
        </div>
    </section>
    @endif

    {{-- B. RESUMEN CLIENTES --}}
    @if(!empty($data['customers']))
    <section class="rounded-2xl border border-purple-200 bg-purple-50 p-4 sm:p-5">
        <h2 class="text-sm font-bold text-purple-900 uppercase tracking-wide">Clientes / Portal</h2>
        <div class="mt-3 grid grid-cols-3 gap-3">
            <div class="rounded-xl bg-white p-3 text-center shadow-sm">
                <p class="text-2xl font-bold text-purple-700">{{ $data['customer_summary']['new_count'] }}</p>
                <p class="text-xs text-slate-500">Nuevos (30 días)</p>
            </div>
            <div class="rounded-xl bg-white p-3 text-center shadow-sm">
                <p class="text-2xl font-bold text-amber-600">{{ $data['customer_summary']['no_portal_count'] }}</p>
                <p class="text-xs text-slate-500">Sin portal</p>
            </div>
            <div class="rounded-xl bg-white p-3 text-center shadow-sm">
                <p class="text-2xl font-bold text-red-600">{{ $data['customer_summary']['portal_no_access_count'] }}</p>
                <p class="text-xs text-slate-500">Portal sin ingreso</p>
            </div>
        </div>
    </section>
    @endif

    {{-- TABS --}}
    <div class="flex gap-1 rounded-xl bg-slate-200 p-1">
        @if($data['summary']['shortage_count'] > 0)
        <button type="button" x-on:click="tab = 'transfers'"
            class="flex-1 rounded-lg px-4 py-2.5 text-sm font-semibold transition"
            :class="tab === 'transfers' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'">
            Traslados ({{ $data['summary']['transfer_count'] }})
        </button>
        <button type="button" x-on:click="tab = 'purchases'"
            class="flex-1 rounded-lg px-4 py-2.5 text-sm font-semibold transition"
            :class="tab === 'purchases' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'">
            Compras ({{ $data['summary']['purchase_count'] }})
        </button>
        @endif
        @if(!empty($data['customers']))
        <button type="button" x-on:click="tab = 'customers'"
            class="flex-1 rounded-lg px-4 py-2.5 text-sm font-semibold transition"
            :class="tab === 'customers' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'">
            Clientes ({{ count($data['customers']) }})
        </button>
        @endif
    </div>

    {{-- C. TRASLADOS SUGERIDOS --}}
    <div x-show="tab === 'transfers'" x-cloak>
        @if(empty($data['transfer_suggestions']))
            <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center">
                <p class="text-sm text-slate-500">No hay traslados sugeridos. Todos los productos sin stock deben cubrirse mediante compra.</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($data['transfer_suggestions'] as $suggestion)
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-base font-bold text-slate-800">{{ $suggestion['product_name'] }}</h3>
                            <p class="mt-0.5 text-xs text-slate-500">ID: {{ $suggestion['product_id'] }}</p>
                        </div>
                        <a href="{{ route('transferencias.create', [
                            'prefill' => json_encode(['products' => [['product_id' => $suggestion['product_id'], 'quantity' => $suggestion['suggested_quantity']]], 'from_branch_id' => $suggestion['from_branch_id'], 'to_branch_id' => $suggestion['to_branch_id']])
                        ]) }}"
                            class="min-h-[44px] inline-flex items-center gap-2 rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-semibold text-slate-900 hover:bg-amber-600">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                            Preparar Traslado
                        </a>
                    </div>

                    <div class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-xs font-semibold text-slate-500 uppercase">Origen</p>
                            <p class="font-medium text-slate-800">{{ $suggestion['from_branch_name'] }}</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <p class="text-xs font-semibold text-slate-500 uppercase">Destino</p>
                            <p class="font-medium text-slate-800">{{ $suggestion['to_branch_name'] }}</p>
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                        <div class="rounded-xl bg-blue-50 p-3 text-center">
                            <p class="text-lg font-bold text-blue-800">{{ $suggestion['available_quantity'] }}</p>
                            <p class="text-xs text-blue-600">Disponible origen</p>
                        </div>
                        <div class="rounded-xl bg-amber-50 p-3 text-center">
                            <p class="text-lg font-bold text-amber-800">{{ $suggestion['suggested_quantity'] }}</p>
                            <p class="text-xs text-amber-600">Cantidad sugerida</p>
                        </div>
                    </div>

                    <p class="mt-3 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">{{ $suggestion['reason'] }}</p>
                </article>
                @endforeach
            </div>
        @endif
    </div>

    {{-- D. COMPRAS SUGERIDAS --}}
    <div x-show="tab === 'purchases'" x-cloak>
        @if(empty($data['purchase_groups']))
            <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center">
                <p class="text-sm text-slate-500">No hay compras sugeridas. Todos los productos se cubren mediante traslado.</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($data['purchase_groups'] as $group)
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-base font-bold text-slate-800">{{ $group['supplier_name'] }}</h3>
                            @if($group['supplier_phone'])
                                <p class="mt-0.5 text-xs text-slate-500">
                                    @if($group['supplier_country_phone_code'])+{{ $group['supplier_country_phone_code'] }} @endif{{ $group['supplier_phone'] }}
                                </p>
                            @endif
                            @if($group['supplier_email'])
                                <p class="text-xs text-slate-500">{{ $group['supplier_email'] }}</p>
                            @endif
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold text-slate-800">₡{{ number_format((float) $group['total_estimated_cost'], 2) }}</p>
                            <p class="text-xs text-slate-500">Total estimado</p>
                        </div>
                    </div>

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-xs text-slate-500">
                                    <th class="pb-2 pr-3">Producto</th>
                                    <th class="pb-2 pr-3 text-right">Cant.</th>
                                    <th class="pb-2 pr-3 text-right">Costo Unit.</th>
                                    <th class="pb-2 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($group['items'] as $item)
                                <tr class="border-b border-slate-100">
                                    <td class="py-2 pr-3">
                                        <p class="font-medium text-slate-800">{{ $item['product_name'] }}</p>
                                        <p class="text-xs text-slate-500">{{ $item['internal_code'] ?? 'S/C' }}@if($item['supplier_product_code']) &middot; {{ $item['supplier_product_code'] }}@endif</p>
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums">{{ $item['suggested_quantity'] }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums">₡{{ number_format((float) $item['unit_cost'], 2) }}</td>
                                    <td class="py-2 text-right font-medium tabular-nums">₡{{ number_format((float) $item['line_total'], 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3 flex justify-end">
                        <a href="{{ route('compras.create', [
                            'prefill' => json_encode([
                                'supplier_id' => $group['supplier_id'],
                                'supplier_name' => $group['supplier_name'],
                                'items' => collect($group['items'])->map(fn($i) => ['product_id' => $i['product_id'], 'name' => $i['product_name'], 'internal_code' => $i['internal_code'], 'quantity' => $i['suggested_quantity'], 'unit_cost' => $i['unit_cost']])->values()->all()
                            ])
                        ]) }}"
                            class="min-h-[44px] inline-flex items-center gap-2 rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-semibold text-slate-900 hover:bg-amber-600">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                            Preparar Compra
                        </a>
                    </div>
                </article>
                @endforeach
            </div>
        @endif
    </div>

    {{-- E. CLIENTES / PORTAL --}}
    <div x-show="tab === 'customers'" x-cloak>
        @if(empty($data['customers']))
            <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center">
                <p class="text-sm text-slate-500">No hay clientes registrados en esta empresa.</p>
            </div>
        @else
            {{-- Búsqueda --}}
            <div class="mb-4">
                <input type="text" x-model="search" placeholder="Buscar cliente..."
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:border-purple-500 focus:outline-none focus:ring-2 focus:ring-purple-500/40">
            </div>

            <div class="space-y-3">
                @foreach($data['customers'] as $customer)
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
                    x-show="search === '' || '{{ strtolower($customer['name']) }}'.includes(search.toLowerCase()) || '{{ strtolower($customer['customer_code'] ?? '') }}'.includes(search.toLowerCase())">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-bold text-slate-800">{{ $customer['name'] }}</h3>
                                @if($customer['is_new'])
                                    <span class="inline-flex rounded-full bg-purple-100 px-2 py-0.5 text-xs font-semibold text-purple-700">Nuevo</span>
                                @endif
                            </div>
                            @if($customer['phone'])
                                <p class="mt-0.5 text-xs text-slate-500">
                                    @if($customer['phone_country_code'])+{{ $customer['phone_country_code'] }} @endif{{ $customer['phone'] }}
                                </p>
                            @endif
                            @if($customer['customer_code'])
                                <p class="text-xs text-slate-400">{{ $customer['customer_code'] }}</p>
                            @endif

                            {{-- Portal status --}}
                            <div class="mt-2">
                                @if($customer['portal_status']['key'] === 'sin_portal')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                                        Sin portal
                                    </span>
                                @elseif($customer['portal_status']['key'] === 'portal_creado')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                        Portal creado — Nunca ingresó
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-700">
                                        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                                        Ya ingresó
                                    </span>
                                    @if($customer['portal_status']['last_login_at'])
                                        <p class="mt-1 text-xs text-slate-400">Último acceso: {{ \Carbon\Carbon::parse($customer['portal_status']['last_login_at'])->format('d/m/Y H:i') }}</p>
                                    @endif
                                @endif
                            </div>
                        </div>

                        {{-- CTAs --}}
                        <div class="flex shrink-0 gap-2">
                            <a href="{{ route('clientes.show', $customer['id']) }}"
                                class="min-h-[44px] inline-flex items-center gap-2 rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                                Ver Cliente
                            </a>
                            @if($customer['whatsapp_url'])
                                <a href="{{ $customer['whatsapp_url'] }}" target="_blank" rel="noopener"
                                    class="min-h-[44px] inline-flex items-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                    WhatsApp
                                </a>
                            @else
                                <span class="inline-flex min-h-[44px] items-center gap-2 rounded-xl bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-400 cursor-not-allowed" title="Sin teléfono válido">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                    Sin teléfono
                                </span>
                            @endif
                        </div>
                    </div>
                </article>
                @endforeach
            </div>
        @endif
    </div>

    {{-- F. SEGUIMIENTO --}}
    @if($data['follow_up']['pending_count'] > 0)
    <section class="rounded-2xl border border-blue-200 bg-blue-50 p-4 sm:p-5">
        <h2 class="text-sm font-bold text-blue-900 uppercase tracking-wide">En trámite</h2>
        <p class="mt-1 text-sm text-blue-700">{{ $data['follow_up']['pending_count'] }} traslado(s) pendiente(s) hacia esta sucursal.</p>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-blue-200 text-left text-xs text-blue-600">
                        <th class="pb-2">Producto</th>
                        <th class="pb-2 text-right">Cantidad</th>
                        <th class="pb-2">Número</th>
                        <th class="pb-2">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['follow_up']['pending_transfers'] as $pending)
                    <tr class="border-b border-blue-100">
                        <td class="py-2 font-medium text-blue-800">{{ $pending->product_name }}</td>
                        <td class="py-2 text-right tabular-nums">{{ $pending->quantity }}</td>
                        <td class="py-2 text-blue-700">{{ $pending->transfer_number }}</td>
                        <td class="py-2 text-blue-700">{{ $pending->status }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif

</div>
@endsection
