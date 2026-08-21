@extends('layouts.app')

@section('title', 'Punto de venta')

@section('content')
<div x-data="posTerminal" x-cloak @keydown.enter.window="handleGlobalEnter($event)" class="space-y-5">
    <section class="rounded-2xl bg-slate-900 p-4 text-white shadow-lg">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div class="grid flex-1 grid-cols-2 gap-3 md:grid-cols-4">
                <div><p class="text-xs uppercase text-slate-400">Empresa</p><p class="font-semibold">{{ $company->trade_name }}</p></div>
                <div><p class="text-xs uppercase text-slate-400">Sucursal</p><p class="font-semibold">{{ $branch->name }}</p></div>
                <div><p class="text-xs uppercase text-slate-400">Cajero</p><p class="font-semibold">{{ $cashier->name }}</p></div>
                <div><p class="text-xs uppercase text-slate-400">Estado de caja</p><p class="font-semibold text-amber-400">@if($cashSession) Caja abierta: {{ $cashSession->session_number }} — {{ $cashSession->cashRegister->name }} @elseif($cashSettings->require_open_session) Debe abrir una caja antes de cobrar @else Sin apertura de caja — cobro permitido temporalmente @endif</p>@if(!$cashSession && $canOpenCash)<a href="{{ route('cash.open.create') }}" class="text-xs text-amber-300 underline">Abrir caja</a>@endif</div>
            </div>
            <a href="{{ route('dashboard') }}"
               class="self-end rounded-xl border border-slate-600 px-5 py-2 font-medium hover:bg-slate-800 xl:self-auto">
                Volver
            </a>
        </div>
    </section>

    @if($cashSettings->session_mode === \App\Models\CompanyCashSetting::SESSION_MODE_SHARED && $cashSessions->count() > 1)
        <section class="rounded-2xl border border-amber-300 bg-amber-50 p-4"><label for="cash-session" class="font-semibold text-amber-900">Caja / Sesión para cobrar</label><select id="cash-session" x-model="cashSessionId" class="mt-2 w-full rounded-xl border-amber-300"><option value="">Seleccione una sesión</option>@foreach($cashSessions as $session)<option value="{{ $session->id }}">{{ $session->session_number }} — {{ $session->cashRegister->name }}</option>@endforeach</select></section>
    @endif

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-5">
            <section class="relative rounded-2xl bg-white p-4 shadow-sm">
                <label for="pos-product-search" class="mb-2 block text-sm font-semibold text-slate-700">Agregar producto</label>
                <div class="relative">
                    <input id="pos-product-search"
                           x-ref="searchInput"
                           x-model="query"
                           @input.debounce.180ms="searchProducts"
                           @keydown.down.prevent="moveSelection(1)"
                           @keydown.up.prevent="moveSelection(-1)"
                           @keydown.enter.prevent="addSelected"
                           @keydown.escape="closeResults"
                           type="search"
                           autofocus
                           autocomplete="off"
                           placeholder="Buscar por nombre, código o escanear código de barras…"
                           class="w-full rounded-2xl border-2 border-slate-300 bg-slate-50 px-5 py-4 text-lg outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                    <span x-show="loading" class="absolute right-5 top-1/2 -translate-y-1/2 text-sm text-slate-500">Buscando…</span>
                </div>

                <div x-show="resultsOpen" class="absolute left-4 right-4 z-30 mt-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
                    <template x-for="(product, index) in results" :key="product.id">
                        <button type="button"
                                @click="addProduct(product)"
                                @mouseenter="selectedIndex = index"
                                :disabled="!product.can_add_to_cart"
                                :class="selectedIndex === index ? 'bg-amber-50 ring-1 ring-inset ring-amber-300' : 'hover:bg-slate-50'"
                                class="grid w-full gap-2 border-b border-slate-100 px-4 py-3 text-left last:border-0 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:opacity-75 md:grid-cols-[3.5rem_minmax(0,1fr)_auto_auto] md:items-center">
                            <div class="flex h-14 w-14 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                                <img x-show="product.has_image" :src="product.image_url" :alt="product.name"
                                     @click.stop="openImage(product)"
                                     class="h-full w-full cursor-zoom-in object-contain p-1">
                                <svg x-show="!product.has_image" class="h-7 w-7 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7.5 12 3 4 7.5m16 0v9L12 21m8-13.5-8 4.5m0 9-8-4.5v-9m8 13.5v-9M4 7.5l8 4.5"/>
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-slate-800" x-text="product.name"></p>
                                <p class="text-xs text-slate-500">
                                    Código: <span x-text="product.internal_code || '—'"></span>
                                    <template x-if="product.matched_barcode">
                                        <span> · Barra: <span x-text="product.matched_barcode"></span></span>
                                    </template>
                                </p>
                            </div>
                            <div class="text-sm text-slate-600">
                                <span x-show="product.controls_inventory && product.available_stock > 0">Stock: <strong x-text="formatQuantity(product.available_stock)"></strong></span>
                                <span x-show="product.controls_inventory && product.available_stock <= 0" class="font-semibold text-red-600">Sin existencia en esta sucursal</span>
                                <span x-show="!product.controls_inventory" class="rounded-full bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-700">Servicio / no controlado</span>
                                <template x-if="product.other_branch_stock && product.other_branch_stock.length">
                                    <p class="mt-1 text-xs font-medium text-blue-700">
                                        Disponible en otras sucursales: <span x-text="otherStockLabel(product)"></span>
                                    </p>
                                </template>
                            </div>
                            <p class="font-bold text-slate-900" x-text="money(product.sale_price)"></p>
                        </button>
                    </template>
                    <p x-show="!loading && results.length === 0" class="px-4 py-6 text-center text-sm text-slate-500">No se encontraron productos.</p>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl bg-white shadow-sm">
                <div class="border-b border-slate-200 px-5 py-4">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-bold text-slate-800">Carrito temporal</h2>
                        <button x-show="cart.length" type="button" @click="clearCart" class="rounded-lg px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">Limpiar carrito</button>
                    </div>
                    <p x-show="notice" x-text="notice" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800"></p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-slate-100 text-xs uppercase text-slate-600">
                            <tr>
                                <th class="px-4 py-3 text-left">Producto</th>
                                <th class="px-4 py-3 text-center">Cantidad</th>
                                <th class="px-4 py-3 text-right">Precio</th>
                                <th class="px-4 py-3 text-right">Descuento</th>
                                <th class="px-4 py-3 text-right">Impuesto</th>
                                <th class="px-4 py-3 text-right">Total</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, index) in cart" :key="item.id">
                                <tr class="border-b border-slate-100" :class="exceedsStock(item) ? 'bg-red-50' : ''">
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                                                <img x-show="item.has_image" :src="item.image_url" :alt="item.name"
                                                     @click="openImage(item)"
                                                     class="h-full w-full cursor-zoom-in object-contain p-1">
                                                <svg x-show="!item.has_image" class="h-6 w-6 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7.5 12 3 4 7.5m16 0v9L12 21m8-13.5-8 4.5m0 9-8-4.5v-9m8 13.5v-9M4 7.5l8 4.5"/>
                                                </svg>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-slate-800" x-text="item.name"></p>
                                                <p class="text-xs text-slate-500" x-text="item.internal_code"></p>
                                                <p x-show="exceedsStock(item)" class="mt-1 text-xs font-semibold text-red-600">Cantidad superior al stock de esta sucursal.</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex items-center justify-center gap-2">
                                            <button type="button" @click="decrease(item)" class="h-9 w-9 rounded-lg bg-slate-200 text-lg font-bold hover:bg-slate-300">−</button>
                                            <span class="min-w-8 text-center font-bold" x-text="formatQuantity(item.quantity)"></span>
                                            <button type="button" @click="increase(item)" class="h-9 w-9 rounded-lg bg-amber-500 text-lg font-normal text-black hover:bg-amber-600">+</button>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <template x-if="canOverridePrice">
                                            <div class="flex flex-col items-end gap-1">
                                                <input x-model="item._unitPrice"
                                                       type="number"
                                                       min="0.0001"
                                                       step="0.0001"
                                                       inputmode="decimal"
                                                       class="w-24 rounded border border-amber-300 bg-amber-50 px-2 py-1 text-right text-sm font-bold text-amber-900"
                                                       :placeholder="String(item.sale_price)"
                                                       :title="'Precio original: ' + money(item.sale_price)">
                                                <span x-show="Number(item._unitPrice) > 0 && Number(item._unitPrice) !== Number(item.sale_price)"
                                                      class="text-[11px] font-semibold text-amber-700">
                                                    Original: <span x-text="money(item.sale_price)"></span>
                                                </span>
                                            </div>
                                        </template>
                                        <span x-show="!canOverridePrice" x-text="money(item.sale_price)"></span>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <template x-if="canDiscount">
                                            <div class="flex items-center justify-end gap-1">
                                                <select x-model="item._discountType"
                                                        class="w-14 rounded border border-amber-300 bg-amber-50 px-1 py-1 text-xs font-bold text-amber-900">
                                                    <option value="fixed">₡</option>
                                                    <option value="percentage">%</option>
                                                </select>
                                                <input x-model="item._discount"
                                                       type="number"
                                                       min="0"
                                                       step="0.0001"
                                                       inputmode="decimal"
                                                       class="w-20 rounded border border-amber-300 bg-amber-50 px-2 py-1 text-right text-sm"
                                                       placeholder="0">
                                            </div>
                                        </template>

                                    </td>
                                    <td class="px-4 py-4 text-right" x-text="money(lineTax(item, index))"></td>
                                    <td class="px-4 py-4 text-right font-bold" x-text="money(lineTotal(item, index))"></td>
                                    <td class="px-4 py-4 text-right"><button type="button" @click="remove(item)" class="rounded-lg px-3 py-2 text-red-600 hover:bg-red-50">Eliminar</button></td>
                                </tr>
                            </template>
                            <tr x-show="cart.length === 0">
                                <td colspan="7" class="py-16 text-center text-slate-500">Busque o escanee un producto para iniciar.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="space-y-5">
            <section class="relative rounded-2xl bg-white p-5 shadow-sm">
                <p x-show="successMessage" x-text="successMessage" class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm font-semibold text-green-700"></p>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-500">Cliente</p>
                        <p class="mt-1 text-lg font-bold text-slate-800" x-text="selectedCustomer ? selectedCustomer.name : 'Consumidor Final'"></p>
                        <p x-show="selectedCustomer" class="text-sm text-slate-500">
                            Identificación: <span x-text="selectedCustomer?.identification || '—'"></span>
                        </p>
                    </div>
                    <div class="flex flex-col gap-2">
                        @can('clientes.crear')
                            <button type="button" @click="openQuickCustomer"
                                    class="rounded-lg bg-amber-500 px-3 py-2 text-xs font-normal text-black hover:bg-amber-600">
                                + Nuevo cliente
                            </button>
                        @endcan
                        <button x-show="selectedCustomer || suspended.customerInvalid" type="button" @click="clearCustomer"
                                class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                            Quitar cliente
                        </button>
                    </div>
                </div>

                <input type="hidden" name="customer_id" :value="customerId ?? ''">

                <div class="mt-4">
                    <label for="pos-customer-search" class="mb-2 block text-sm font-semibold text-slate-700">Buscar cliente</label>
                    <input id="pos-customer-search"
                           x-ref="customerSearchInput"
                           x-model="customerQuery"
                           @input.debounce.180ms="searchCustomers"
                           @keydown.down.prevent="moveCustomerSelection(1)"
                           @keydown.up.prevent="moveCustomerSelection(-1)"
                           @keydown.enter.prevent="selectMarkedCustomer"
                           @keydown.escape="closeCustomerResults"
                           type="search"
                           autocomplete="off"
                           placeholder="Nombre, identificación, teléfono o correo…"
                           class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0">
                </div>

                <div x-show="customerResultsOpen"
                     class="absolute left-5 right-5 z-40 mt-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
                    <template x-for="(customer, index) in customerResults" :key="customer.id">
                        <button type="button"
                                @click="selectCustomer(customer)"
                                @mouseenter="customerSelectedIndex = index"
                                :class="customerSelectedIndex === index ? 'bg-amber-50 ring-1 ring-inset ring-amber-300' : 'hover:bg-slate-50'"
                                class="block w-full border-b border-slate-100 px-4 py-3 text-left last:border-0">
                            <p class="font-semibold text-slate-800" x-text="customer.name"></p>
                            <p class="text-xs text-slate-500">
                                <span x-text="customer.identification || 'Sin identificación'"></span>
                                <span x-show="customer.phone || customer.mobile"> · <span x-text="customer.phone || customer.mobile"></span></span>
                            </p>
                        </button>
                    </template>
                    <p x-show="!customerLoading && customerResults.length === 0" class="px-4 py-5 text-center text-sm text-slate-500">No se encontraron clientes activos.</p>
                </div>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm">
                <h2 class="font-bold text-slate-800">Formas de pago disponibles</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse($paymentMethods as $paymentMethod)
                        <span class="rounded-full border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">{{ $paymentMethod->name }}</span>
                    @empty
                        <span class="text-sm text-slate-500">No hay formas de pago activas.</span>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl bg-slate-900 p-5 text-white shadow-lg">
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between"><span class="text-slate-300">Subtotal</span><strong x-text="money(subtotal)"></strong></div>
                    <template x-if="canDiscount">
                        <div class="flex items-center justify-between gap-2">
                            <span class="shrink-0 text-slate-300">Dto. general</span>
                            <div class="flex items-center gap-1">
                                <select x-model="_generalDiscountType"
                                        class="w-14 rounded-lg border border-amber-400/50 bg-slate-800 px-1 py-1 text-xs font-bold text-amber-400">
                                    <option value="fixed">₡</option>
                                    <option value="percentage">%</option>
                                </select>
                                <input x-model="_generalDiscountInput"
                                       type="number"
                                       min="0"
                                       step="0.0001"
                                       inputmode="decimal"
                                       class="w-24 rounded-lg border border-amber-400/50 bg-slate-800 px-2 py-1 text-right text-sm font-bold text-amber-400 placeholder-amber-400/50"
                                       placeholder="0">
                            </div>
                        </div>
                    </template>
                    <div class="flex justify-between"><span class="text-slate-300">Descuento</span><strong class="text-amber-400" x-text="money(totalDiscount)"></strong></div>
                    <div class="flex justify-between"><span class="text-slate-300">Impuesto</span><strong x-text="money(taxTotal)"></strong></div>
                    <div x-show="roundingTotal !== 0" class="flex justify-between"><span class="text-slate-400">Redondeo</span><strong x-text="money(roundingTotal)"></strong></div>
                </div>
                <div class="my-5 border-t border-slate-700"></div>
                <div class="flex items-end justify-between"><span class="text-lg">Total</span><strong class="text-3xl text-amber-400" x-text="money(grandTotal)"></strong></div>
                <div x-show="activeQuote" class="mt-3 rounded-xl bg-blue-50 px-4 py-2 text-sm text-blue-800">
                    Cotización <strong x-text="activeQuote.quote_number"></strong> cargada — se cobrará con sus valores.
                </div>
                <button type="button" @click="saveAsQuote" :disabled="!cart.length || savingQuote"
                        class="mt-3 w-full rounded-2xl border border-slate-600 px-5 py-3 text-sm text-slate-200 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">
                    <span x-text="savingQuote ? 'Guardando…' : 'Guardar cotización'"></span>
                </button>
                @can('ventas.crear')
                    <button type="button" @click="openCheckout" :disabled="!canCheckout"
                            class="mt-5 w-full rounded-2xl bg-amber-500 px-5 py-4 text-lg font-normal text-black hover:bg-amber-600 disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-400">
                        Cobrar
                    </button>
                @endcan
            </section>
        </aside>
    </div>

    <section class="rounded-2xl bg-white p-3 shadow-sm">
        <div class="flex gap-2 overflow-x-auto pb-1">

      <button
    type="button"
    @click.prevent.stop="
        documentType = 'electronic_ticket';
        notice = '';
    "
    :class="documentType === 'electronic_ticket'
        ? 'bg-amber-500 text-black'
        : 'border border-slate-300 bg-white text-slate-700'"
    class="whitespace-nowrap rounded-xl px-4 py-3 font-normal">
    Tiquete electrónico
</button>

<button
    type="button"
    @click="
        if (customerId) {
            documentType = 'electronic_invoice';
        } else {
            notice = 'Seleccione un cliente antes de usar Factura electrónica.';
        }
    "
    :class="documentType === 'electronic_invoice'
        ? 'bg-amber-500 text-black'
        : 'border border-slate-300 bg-white text-slate-700'"
    class="whitespace-nowrap rounded-xl px-4 py-3 font-normal hover:bg-amber-100">
    Factura electrónica
</button>

            <button type="button" @click="suspendCurrent" :disabled="cart.length === 0 || suspended.saving" x-text="suspended.activeId && suspended.recoveryToken ? 'Volver a suspender' : 'Suspender'" class="whitespace-nowrap rounded-xl border border-amber-400 px-4 py-3 text-sm font-bold text-amber-800 disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"></button>
            <button type="button" @click="openSuspended" class="whitespace-nowrap rounded-xl bg-slate-800 px-4 py-3 text-sm font-bold text-white">Suspendidas</button>
            @foreach(['Pedido', 'Apartado', 'Abono', 'Cotización', 'Nota de crédito', 'Nota de débito'] as $option)
                <button type="button" disabled class="whitespace-nowrap rounded-xl border border-slate-200 bg-slate-100 px-4 py-3 text-sm font-medium text-slate-400" title="Próximamente">{{ $option }} · Próximamente</button>
            @endforeach
        </div>
    </section>

    <div x-show="suspended.open" x-cloak @click.self="suspended.open = false" class="fixed inset-0 z-[115] flex items-center justify-center bg-slate-950/75 p-4" role="dialog" aria-modal="true" aria-label="Ventas suspendidas">
        <div class="max-h-[90vh] w-full max-w-5xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
            <div class="flex items-center justify-between"><div><h2 class="text-2xl font-bold">Ventas suspendidas</h2><p class="text-sm text-slate-500">Sucursal activa</p></div><button type="button" @click="suspended.open = false" class="text-3xl">×</button></div>
            <p x-show="suspended.error" x-text="suspended.error" class="mt-4 rounded-lg bg-red-50 p-3 text-sm font-semibold text-red-700"></p>
            <div class="mt-5 overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-100"><tr><th class="p-3 text-left">Número</th><th class="p-3 text-left">Hora</th><th class="p-3 text-left">Cajero</th><th class="p-3 text-left">Cliente</th><th class="p-3 text-right">Líneas</th><th class="p-3 text-right">Total</th><th class="p-3 text-left">Estado</th><th class="p-3"></th></tr></thead>
                <tbody><template x-for="sale in suspended.list" :key="sale.id"><tr class="border-b"><td class="p-3 font-bold" x-text="sale.suspension_number"></td><td class="p-3" x-text="new Date(sale.suspended_at).toLocaleString('es-CR')"></td><td class="p-3" x-text="sale.cashier"></td><td class="p-3" x-text="sale.customer"></td><td class="p-3 text-right" x-text="sale.items_count"></td><td class="p-3 text-right font-bold" x-text="money(sale.estimated_total)"></td><td class="p-3" x-text="sale.status"></td><td class="p-3 text-right"><button type="button" @click="recoverSuspended(sale)" class="rounded-lg bg-amber-500 px-3 py-2 font-normal text-black hover:bg-amber-600">Recuperar</button> <button x-show="suspended.canCancel" type="button" @click="cancelSuspended(sale)" class="rounded-lg px-3 py-2 font-bold text-red-600">Cancelar</button></td></tr></template></tbody>
            </table><p x-show="!suspended.loading && suspended.list.length === 0" class="p-8 text-center text-slate-500">No hay ventas suspendidas disponibles.</p></div>
        </div>
    </div>

    <div x-show="checkout.open" x-cloak @click.self="requestCloseCheckout" @keydown.escape.window="requestCloseCheckout" @keydown.enter.window="handleCheckoutEnter($event)"
         class="fixed inset-0 z-[120] flex items-center justify-center bg-[#111111]/80 p-3 sm:p-5" role="dialog" aria-modal="true" aria-label="Cobrar venta">
        <div class="flex max-h-[90vh] w-full max-w-[1120px] flex-col overflow-hidden rounded-3xl border border-[#B9BDC2] bg-white shadow-2xl">
            <template x-if="!checkout.result">
                <div class="flex min-h-0 flex-1 flex-col">
                    <header class="flex items-center justify-between gap-4 border-b border-[#B9BDC2]/60 bg-[#111111] px-5 py-4 text-white sm:px-7">
                        <div><h2 class="text-2xl font-black tracking-tight">Cobrar venta</h2><p class="mt-1 text-sm text-[#B9BDC2]"><span x-text="`${cart.length} producto${cart.length === 1 ? '' : 's'}`"></span> · <span x-text="selectedCustomer?.name || 'Consumidor Final'"></span></p></div>
                        <button type="button" @click="requestCloseCheckout" :disabled="checkout.processing" class="flex h-11 w-11 items-center justify-center rounded-full border border-white/25 text-2xl hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-[#B1922D]" aria-label="Cerrar cobro">×</button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
                        <div class="grid gap-5 lg:grid-cols-[0.9fr_1.1fr]">
                            <section class="space-y-4" aria-label="Resumen del cobro">
                                <div class="rounded-2xl border border-[#B9BDC2] bg-slate-50 p-5">
                                    <h3 class="font-black text-[#111111]">Resumen permanente</h3>
                                    <dl class="mt-4 space-y-2 text-sm"><div class="flex justify-between"><dt>Subtotal</dt><dd class="font-bold" x-text="money(subtotal)"></dd></div><div class="flex justify-between"><dt>Descuento</dt><dd class="font-bold" x-text="money(totalDiscount)"></dd></div><div class="flex justify-between"><dt>Impuesto</dt><dd class="font-bold" x-text="money(taxTotal)"></dd></div><div x-show="roundingTotal !== 0" class="flex justify-between"><dt>Ajuste de redondeo</dt><dd class="font-bold" x-text="money(roundingTotal)"></dd></div></dl>
                                    <div class="mt-4 flex items-end justify-between border-t border-[#B9BDC2] pt-4"><span class="font-bold">Total</span><strong class="text-3xl text-[#111111]" x-text="money(grandTotal)"></strong></div>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-3"><div class="rounded-2xl bg-[#111111] p-4 text-white"><span class="text-sm font-semibold text-[#B9BDC2]">Total aplicado</span><strong class="mt-2 block text-xl font-extrabold sm:text-2xl" x-text="money(appliedTotal)"></strong></div><div class="rounded-2xl bg-[#111111] p-4 text-white"><span class="text-sm font-semibold text-[#B9BDC2]">Saldo pendiente</span><strong class="mt-2 block text-xl font-extrabold sm:text-2xl" :class="pendingBalance > 0 ? 'text-[#B1922D]' : 'text-emerald-400'" x-text="money(pendingBalance)"></strong></div><div class="rounded-2xl bg-[#111111] p-4 text-white"><span class="text-sm font-semibold text-[#B9BDC2]">Vuelto total</span><strong class="mt-2 block text-xl font-extrabold sm:text-2xl" :class="totalPaymentChange > 0 ? 'text-emerald-400' : 'text-[#B9BDC2]'" x-text="money(totalPaymentChange)"></strong></div></div>
                                <div class="rounded-2xl border border-[#B9BDC2] p-4">
                                    <div class="flex items-center justify-between"><h3 class="font-black">Pagos aplicados</h3><span x-show="checkout.payments.length > 1" class="rounded-full bg-[#B1922D]/15 px-3 py-1 text-xs font-black text-[#806817]">Pago mixto</span></div>
                                    <p x-show="checkout.payments.length === 0" class="py-7 text-center text-sm text-slate-500">Seleccione una forma de pago para comenzar</p>
                                    <div class="mt-3 space-y-2"><template x-for="(payment, index) in checkout.payments" :key="payment.payment_method_id"><div class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-3"><div class="min-w-0"><div class="flex gap-2"><strong x-text="payment.method_name"></strong><span class="font-black text-[#806817]" x-text="money(payment.amount)"></span></div><p x-show="payment.reference" class="truncate text-xs text-slate-500" x-text="`Referencia: ${payment.reference}`"></p><p x-show="payment.received_amount != payment.amount || payment.change_amount > 0" class="text-xs text-emerald-700" x-text="`Recibido ${money(payment.received_amount)} · Vuelto ${money(payment.change_amount)}`"></p></div><button type="button" @click="removePayment(index)" :disabled="checkout.processing" class="rounded-lg px-2 py-1 text-xs font-bold text-red-600 hover:bg-red-50">Quitar</button></div></template></div>
                                </div>
                            </section>

                            <section class="space-y-4" aria-label="Monto y formas de pago">
                                <div class="rounded-2xl border-2 border-[#B1922D] p-5"><div class="flex items-center justify-between gap-3"><label for="checkout-amount" class="font-black text-[#111111]">Monto a aplicar</label><button type="button" @click="usePendingBalance" class="text-sm font-bold text-[#806817] underline">Usar saldo pendiente</button></div><div class="mt-2 flex min-w-0 items-center rounded-xl bg-slate-50 px-4"><span class="shrink-0 text-3xl font-black text-[#806817]">₡</span><input id="checkout-amount" x-ref="checkoutAmount" x-model="checkout.draft.amount" @focus="$event.target.select()" inputmode="numeric" pattern="[0-9]*" :disabled="checkout.processing" class="min-w-0 w-full border-0 bg-transparent py-4 pl-2 text-right text-3xl font-black text-[#111111] focus:ring-0 sm:text-4xl"></div></div>
                                <div><div class="flex items-end justify-between"><div><h3 class="text-lg font-black">Formas de pago</h3><p class="text-xs text-slate-500">Seleccione un método para aplicar el monto.</p></div><span class="text-xs font-semibold text-slate-500">Sin apertura de caja</span></div>
                                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3"><template x-for="method in paymentMethods" :key="method.id"><button type="button" @click="selectPaymentMethod(method)" :disabled="checkout.processing || methodUnavailable(method)" :class="unsupportedPaymentMethod(method) ? 'border-slate-300 bg-slate-100 text-slate-500' : (selectedPaymentMethod?.id === method.id ? 'border-[#111111] bg-amber-500 text-black ring-4 ring-amber-500/30 hover:bg-amber-600' : 'border-amber-500 bg-amber-500 text-black hover:bg-amber-600')" class="min-h-24 rounded-2xl border-2 p-3 text-left font-normal transition focus:outline-none focus:ring-4 focus:ring-amber-600/40 disabled:cursor-not-allowed disabled:border-slate-300 disabled:bg-slate-100 disabled:text-slate-500 disabled:opacity-100"><span :class="unsupportedPaymentMethod(method) ? 'bg-slate-200 text-slate-500' : 'bg-white text-amber-600'" class="flex h-9 w-9 items-center justify-center rounded-full font-normal" x-text="methodInitial(method)"></span><strong class="mt-2 block text-sm font-normal" x-text="method.name"></strong><small :class="unsupportedPaymentMethod(method) ? 'text-slate-500' : 'text-black/85'" class="block" x-text="unsupportedPaymentMethod(method) ? 'Próximamente' : (method.requires_reference ? 'Requiere referencia' : (method.allows_change ? 'Permite vuelto' : 'Aplicación directa'))"></small></button></template></div>
                                </div>

                                <div x-show="selectedPaymentMethod" x-transition class="rounded-2xl border border-[#B9BDC2] bg-slate-50 p-4">
                                    <div class="flex items-center justify-between"><strong x-text="selectedPaymentMethod?.name"></strong><span class="font-black text-[#806817]" x-text="money(Number(checkout.draft.amount))"></span></div>
                                    <div x-show="selectedPaymentMethod?.requires_reference" class="mt-3"><label for="checkout-reference" class="text-sm font-bold">Referencia *</label><input id="checkout-reference" x-ref="checkoutReference" x-model="checkout.draft.reference" @keydown.enter.prevent="addPayment" maxlength="150" :disabled="checkout.processing" class="mt-1 w-full rounded-xl border border-[#B9BDC2] px-4 py-3 focus:border-[#B1922D] focus:ring-[#B1922D]"></div>
                                    <div x-show="selectedPaymentMethod?.allows_change" class="mt-3"><label for="checkout-received" class="text-sm font-bold">Monto recibido</label><input id="checkout-received" x-ref="receivedAmount" x-model="checkout.draft.receivedAmount" @focus="$event.target.select()" @keydown.enter.prevent="addPayment" inputmode="numeric" pattern="[0-9]*" :disabled="checkout.processing" class="mt-1 w-full rounded-xl border border-[#B9BDC2] px-4 py-3 text-right text-2xl font-black focus:border-[#B1922D] focus:ring-[#B1922D]"><div class="mt-2 flex flex-wrap gap-2"><template x-for="amount in suggestedAmounts" :key="amount"><button type="button" @click="checkout.draft.receivedAmount = String(amount)" class="rounded-lg border border-[#B9BDC2] bg-white px-3 py-2 text-sm font-bold hover:border-[#B1922D]" x-text="money(amount)"></button></template></div><div class="mt-3 rounded-xl bg-[#111111] px-4 py-3 text-white"><span class="text-sm font-bold text-[#B9BDC2]">Vuelto</span><strong class="mt-1 block text-right text-2xl font-extrabold text-emerald-400 sm:text-3xl" x-text="money(Math.max(0, Number(checkout.draft.receivedAmount) - Number(checkout.draft.amount)))"></strong></div></div>
                                    <div class="mt-4 flex justify-end gap-2"><button type="button" @click="cancelPaymentDraft" class="rounded-xl border border-[#B9BDC2] px-4 py-2 font-bold">Cancelar</button><button type="button" @click="addPayment" :disabled="!canAddPayment || checkout.processing" class="rounded-xl bg-amber-500 px-5 py-2 font-normal text-black hover:bg-amber-600 focus:outline-none focus:ring-4 focus:ring-amber-600/40 disabled:opacity-40" x-text="selectedPaymentMethod?.allows_change ? 'Agregar efectivo' : 'Agregar pago'"></button></div>
                                </div>
                                <p x-show="checkoutError" x-text="checkoutError" class="rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700"></p>
                            </section>
                        </div>
                    </div>

                    <footer class="grid shrink-0 grid-cols-2 gap-2 border-t border-[#B9BDC2] bg-white p-4 sm:flex sm:justify-end sm:px-6"><button type="button" @click="requestCloseCheckout" :disabled="checkout.processing" class="rounded-xl border border-[#B9BDC2] px-5 py-3 font-bold">Cancelar</button><button type="button" @click="clearPayments" :disabled="checkout.processing || !checkout.payments.length" class="rounded-xl border border-[#B1922D] px-5 py-3 font-bold text-[#806817] disabled:opacity-40">Limpiar pagos</button><button type="button" @click="confirmCheckout" :disabled="!checkoutCanConfirm" class="col-span-2 rounded-xl bg-amber-500 px-6 py-3 text-lg font-normal text-black hover:bg-amber-600 focus:outline-none focus:ring-4 focus:ring-amber-600/40 disabled:cursor-not-allowed disabled:bg-slate-300" x-text="checkout.processing ? 'Procesando…' : `Confirmar cobro — ${money(grandTotal)}`"></button></footer>
                </div>
            </template>
            <template x-if="checkout.result"><div class="overflow-y-auto p-6 text-center sm:p-10"><div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-amber-500 text-3xl font-black text-[#111111]">✓</div><h2 class="mt-4 text-3xl font-black text-[#111111]">Venta completada</h2><p class="mt-2 text-xl font-bold text-[#806817]" x-text="checkout.result.sale_number"></p><div class="mx-auto mt-5 max-w-md rounded-2xl bg-slate-50 p-5"><p>Total: <strong x-text="money(checkout.result.total)"></strong></p><p>Vuelto total: <strong x-text="money(checkout.result.total_change)"></strong></p><div class="mt-3 space-y-1 text-left"><template x-for="payment in checkout.result.payments"><p><strong x-text="payment.method_name"></strong>: <span x-text="money(payment.amount)"></span><span x-show="payment.reference" x-text="` · Ref: ${payment.reference}`"></span><span x-show="payment.change_amount > 0" x-text="` · Recibido ${money(payment.received_amount)} · Vuelto ${money(payment.change_amount)}`"></span></p></template></div></div><p x-show="checkout.result.duplicate" class="mx-auto mt-3 max-w-md rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Esta venta ya había sido procesada.</p><div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row"><a :href="checkout.result.receipt_url" target="_blank" class="rounded-xl bg-[#111111] px-5 py-3 font-normal text-white">Imprimir comprobante</a><button type="button" @click="newSale" class="rounded-xl bg-amber-500 px-5 py-3 font-normal text-black hover:bg-amber-600">Nueva venta</button></div></div></template>
        </div>
    </div>

    <div x-show="imageModal.open"
         x-cloak
         @keydown.escape.window="closeImage"
         @click.self="closeImage"
         class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/75 p-4"
         role="dialog"
         aria-modal="true"
         :aria-label="imageModal.name">
        <div class="relative rounded-2xl bg-white p-4 shadow-2xl" style="max-width: min(680px, 85vw); max-height: min(680px, 80vh);">
            <button type="button" @click="closeImage"
                    class="absolute right-3 top-3 z-10 flex h-11 w-11 items-center justify-center rounded-full bg-slate-900 text-2xl text-white shadow hover:bg-slate-700"
                    aria-label="Cerrar imagen">×</button>
            <h2 class="mb-3 pr-14 text-lg font-bold text-slate-800" x-text="imageModal.name"></h2>
            <img :src="imageModal.url" :alt="imageModal.name"
                 class="mx-auto object-contain"
                 style="max-width: min(640px, 80vw); max-height: min(600px, 68vh);">
        </div>
    </div>

    @can('clientes.crear')
        <div x-show="quickCustomer.open"
             x-cloak
             @keydown.escape.window="closeQuickCustomer"
             @click.self="closeQuickCustomer"
             class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/75 p-4"
             role="dialog"
             aria-modal="true"
             aria-label="Crear cliente rápido">
            <form @submit.prevent="storeQuickCustomer"
                  class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold text-slate-800">Nuevo cliente</h2>
                        <p class="text-sm text-slate-500">Registro básico para seleccionarlo en el POS.</p>
                    </div>
                    <button type="button" @click="closeQuickCustomer"
                            class="flex h-11 w-11 items-center justify-center rounded-full bg-slate-900 text-2xl text-white"
                            aria-label="Cerrar nuevo cliente">×</button>
                </div>

                <input type="hidden" name="_token" value="{{ csrf_token() }}">

                <p x-show="quickCustomer.message" x-text="quickCustomer.message"
                   class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm font-semibold text-red-700"></p>

                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="quick-customer-name" class="mb-1 block text-sm font-semibold text-slate-700">Nombre completo o razón social *</label>
                        <input id="quick-customer-name" x-ref="quickCustomerName" x-model="quickCustomer.form.name" required maxlength="150"
                               class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0">
                        <p x-show="quickCustomer.errors.name" x-text="quickCustomer.errors.name?.[0]" class="mt-1 text-xs text-red-600"></p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Tipo de cliente *</label>
                        <select x-model="quickCustomer.form.customer_type" required class="w-full rounded-xl border border-slate-300 px-4 py-3">
                            <option value="individual">Persona física</option>
                            <option value="company">Empresa</option>
                        </select>
                        <p x-show="quickCustomer.errors.customer_type" x-text="quickCustomer.errors.customer_type?.[0]" class="mt-1 text-xs text-red-600"></p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Tipo de identificación</label>
                        <select x-model="quickCustomer.form.identification_type" class="w-full rounded-xl border border-slate-300 px-4 py-3">
                            <option value="">Seleccione…</option>
                            <option value="01">Cédula física</option>
                            <option value="02">Cédula jurídica</option>
                            <option value="03">DIMEX</option>
                            <option value="04">NITE</option>
                            <option value="05">Extranjero no domiciliado</option>
                        </select>
                        <p x-show="quickCustomer.errors.identification_type" x-text="quickCustomer.errors.identification_type?.[0]" class="mt-1 text-xs text-red-600"></p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Identificación</label>
                        <input x-model="quickCustomer.form.identification" maxlength="50" class="w-full rounded-xl border border-slate-300 px-4 py-3">
                        <p x-show="quickCustomer.errors.identification" x-text="quickCustomer.errors.identification?.[0]" class="mt-1 text-xs text-red-600"></p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Teléfono</label>
                        <input x-model="quickCustomer.form.phone" maxlength="30" class="w-full rounded-xl border border-slate-300 px-4 py-3">
                        <p x-show="quickCustomer.errors.phone" x-text="quickCustomer.errors.phone?.[0]" class="mt-1 text-xs text-red-600"></p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Celular</label>
                        <input x-model="quickCustomer.form.mobile" maxlength="30" class="w-full rounded-xl border border-slate-300 px-4 py-3">
                        <p x-show="quickCustomer.errors.mobile" x-text="quickCustomer.errors.mobile?.[0]" class="mt-1 text-xs text-red-600"></p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Correo</label>
                        <input type="email" x-model="quickCustomer.form.email" maxlength="150" class="w-full rounded-xl border border-slate-300 px-4 py-3">
                        <p x-show="quickCustomer.errors.email" x-text="quickCustomer.errors.email?.[0]" class="mt-1 text-xs text-red-600"></p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" @click="closeQuickCustomer" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700">Cancelar</button>
                    <button type="submit" :disabled="quickCustomer.saving || !quickCustomer.form.name.trim()"
                            class="rounded-xl bg-amber-500 px-5 py-3 font-normal text-black hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-50">
                        <span x-text="quickCustomer.saving ? 'Guardando…' : 'Guardar cliente'"></span>
                    </button>
                </div>
            </form>
        </div>
    @endcan
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('posTerminal', () => ({
        query: '',
        results: [],
        cart: [],
        selectedIndex: 0,
        loading: false,
        requestNumber: 0,
        notice: '',
        imageModal: { open: false, url: null, name: '' },
        customerId: null,
        documentType: 'electronic_ticket',
        selectedCustomer: null,
        customerQuery: '',
        customerResults: [],
        customerSelectedIndex: 0,
        customerLoading: false,
        customerRequestNumber: 0,
        successMessage: '',
        _generalDiscountInput: '',
        _generalDiscountType: 'fixed',
        canDiscount: @json($canDiscount),
        canOverridePrice: @json($canOverridePrice),
        checkoutToken: crypto.randomUUID(),
        quoteId: null,
        activeQuote: null,
        savingQuote: false,
        cashSessionId: @json($cashSession?->id),
        cashSessionRequired: @json($cashSettings->require_open_session || ($cashSettings->session_mode === \App\Models\CompanyCashSetting::SESSION_MODE_SHARED && $cashSessions->count() > 1)),
        paymentMethods: @json($paymentMethods->values()),
        checkout: { open: false, processing: false, payments: [], draft: { methodId: '', amount: '', receivedAmount: '', reference: '' }, error: '', result: null },
        quickCustomer: {
            open: false,
            saving: false,
            errors: {},
            message: '',
            form: { name: '', customer_type: 'individual', identification_type: '', identification: '', phone: '', mobile: '', email: '' },
        },
        suspended: { open: false, loading: false, saving: false, list: [], error: '', activeId: null, recoveryToken: null, warnings: [], customerInvalid: false, canCancel: @json($canCancelSuspended) },

        get resultsOpen() {
            return this.query.trim().length > 0 && (this.loading || this.results.length >= 0);
        },
        get subtotalBeforeGeneralDiscount() {
            return this.decimal4(this.cart.reduce((sum, item) => sum + this.lineSubtotalBeforeGeneral(item), 0));
        },
        get generalDiscount() {
            if (!this.canDiscount) return 0;
            const value = this.numberValue(this._generalDiscountInput);
            if (value <= 0 || this.subtotalBeforeGeneralDiscount <= 0) return 0;
            if (this._generalDiscountType === 'percentage') {
                return this.decimal4(this.subtotalBeforeGeneralDiscount * (Math.min(value, 100) / 100));
            }
            return this.decimal4(Math.min(value, this.subtotalBeforeGeneralDiscount));
        },
        get generalDiscountAllocations() {
            const allocations = this.cart.map(() => 0);
            const base = this.subtotalBeforeGeneralDiscount;
            const discount = this.generalDiscount;
            if (discount <= 0 || base <= 0 || !this.cart.length) return allocations;

            let allocated = 0;
            let lastPositiveIndex = null;

            this.cart.forEach((item, index) => {
                const lineBase = this.lineSubtotalBeforeGeneral(item);
                if (lineBase > 0) lastPositiveIndex = index;
                const share = this.decimal4(discount * (lineBase / base));
                allocations[index] = Math.min(share, lineBase);
                allocated = this.decimal4(allocated + allocations[index]);
            });

            const remainder = this.decimal4(discount - allocated);
            if (lastPositiveIndex !== null && Math.abs(remainder) > 0.0000001) {
                allocations[lastPositiveIndex] = this.decimal4(allocations[lastPositiveIndex] + remainder);
            }

            return allocations;
        },
        get subtotal() {
            return this.decimal4(this.cart.reduce((sum, item, index) => sum + this.lineSubtotal(item, index), 0));
        },
        get taxTotal() {
            return this.decimal4(this.cart.reduce((sum, item, index) => sum + this.lineTax(item, index), 0));
        },
        get totalDiscount() {
            const lineDiscounts = this.cart.reduce((sum, item) => sum + this.lineDiscount(item), 0);
            return this.decimal4(lineDiscounts + this.generalDiscount);
        },
        get grandTotal() {
            return Math.round(this.decimal4(this.subtotal + this.taxTotal));
        },
        get roundingTotal() {
            return this.decimal4(this.grandTotal - this.decimal4(this.subtotal + this.taxTotal));
        },
        get hasInvalidAdjustments() {
            if (this.canOverridePrice && this.cart.some(item => item._unitPrice !== '' && item._unitPrice !== null && this.numberValue(item._unitPrice) <= 0)) return true;

            if (this.canDiscount) {
                for (const item of this.cart) {
                    const value = this.numberValue(item._discount);
                    if (value < 0) return true;
                    if (item._discountType === 'percentage' && value > 100) return true;
                    if (item._discountType === 'fixed' && value > this.lineGross(item)) return true;
                }

                const generalValue = this.numberValue(this._generalDiscountInput);
                if (generalValue < 0) return true;
                if (this._generalDiscountType === 'percentage' && generalValue > 100) return true;
                if (this._generalDiscountType === 'fixed' && generalValue > this.subtotalBeforeGeneralDiscount) return true;
                if (this.generalDiscount > 0 && this.generalDiscount >= this.subtotalBeforeGeneralDiscount) return true;
            }

            return false;
        },
        get availablePaymentMethods() { return this.paymentMethods.filter(method => !['credit', 'loyalty_points'].includes(method.type) && !this.checkout.payments.some(payment => payment.payment_method_id === method.id)); },
        get canCheckout() { return this.cart.length > 0 && (!this.cashSessionRequired || !!this.cashSessionId) && !this.suspended.customerInvalid && !this.hasInvalidAdjustments && this.grandTotal > 0 && !this.cart.some(item => item.unavailable || this.exceedsStock(item)) && this.availablePaymentMethods.length > 0; },
        get selectedPaymentMethod() { return this.paymentMethods.find(method => method.id === Number(this.checkout.draft.methodId)); },
        get appliedTotal() { return this.checkout.payments.reduce((sum, payment) => sum + Number(payment.amount), 0); },
        get pendingBalance() { return Math.max(0, this.grandTotal - this.appliedTotal); },
        get totalPaymentChange() { return this.checkout.payments.reduce((sum, payment) => sum + Number(payment.change_amount), 0); },
        get checkoutCanConfirm() { return !this.checkout.processing && this.checkout.payments.length > 0 && this.pendingBalance === 0; },
        get checkoutError() { return this.checkout.error; },
        get canAddPayment() {
            const method = this.selectedPaymentMethod, amount = Number(this.checkout.draft.amount);
            if (!method || this.checkout.processing || this.methodUnavailable(method) || !/^\d+$/.test(String(this.checkout.draft.amount)) || amount <= 0 || amount > this.pendingBalance) return false;
            if (method.requires_reference && !this.checkout.draft.reference.trim()) return false;
            if (!method.allows_change) return true;
            if (!/^\d+$/.test(String(this.checkout.draft.receivedAmount)) || Number(this.checkout.draft.receivedAmount) < amount) return false;
            return true;
        },
        get suggestedAmounts() {
            const total = Number(this.checkout.draft.amount) || this.pendingBalance;
            return [...new Set([total, 1000, 2000, 5000, 10000, 20000, 50000].filter(value => value >= total))].slice(0, 4);
        },
        get customerResultsOpen() {
            return this.customerQuery.trim().length > 0;
        },
        async readFetchResponse(response) {
            if (response.redirected) throw new Error('La sesión venció. Recargue la página e inténtelo nuevamente.');
            const contentType = (response.headers.get('content-type') || '').toLowerCase();
            let payload = null;
            if (contentType.includes('application/json')) {
                try { payload = await response.json(); } catch (error) { throw new Error('No se pudo completar la operación. Intente nuevamente.'); }
            } else {
                await response.text();
                throw new Error(response.status === 419 ? 'La sesión venció. Recargue la página e inténtelo nuevamente.' : 'No se pudo completar la operación. Intente nuevamente.');
            }
            if (!response.ok) {
                if (response.status === 419) throw new Error('La sesión venció. Recargue la página e inténtelo nuevamente.');
                const requestError = new Error(response.status === 403 ? (payload.message || 'No tiene autorización para realizar esta operación.') : (payload.message || 'No se pudo completar la operación. Intente nuevamente.'));
                requestError.payload = payload;
                requestError.status = response.status;
                throw requestError;
            }
            return payload;
        },
        async searchProducts() {
            const term = this.query.trim();
            const currentRequest = ++this.requestNumber;
            if (!term) {
                this.results = [];
                this.loading = false;
                return;
            }
            this.loading = true;
            try {
                const url = new URL({{ Illuminate\Support\Js::from(route('pos.products.search')) }}, window.location.origin);
                url.searchParams.set('q', term);
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('No fue posible buscar productos.');
                const products = await response.json();
                if (currentRequest !== this.requestNumber) return;
                this.results = products;
                this.selectedIndex = 0;
                const exactBarcode = products.find(product => product.matched_barcode === term);
                if (exactBarcode) this.addProduct(exactBarcode);
            } catch (error) {
                if (currentRequest === this.requestNumber) this.results = [];
            } finally {
                if (currentRequest === this.requestNumber) this.loading = false;
            }
        },
        moveSelection(direction) {
            if (!this.results.length) return;
            this.selectedIndex = (this.selectedIndex + direction + this.results.length) % this.results.length;
        },
        addSelected() {
            if (this.results[this.selectedIndex]) this.addProduct(this.results[this.selectedIndex]);
        },
        addProduct(product) {
            if (!product.can_add_to_cart) {
                this.notice = 'Sin existencia en esta sucursal.';
                return;
            }
            const existing = this.cart.find(item => item.id === product.id);
            if (existing) {
                if (existing.controls_inventory && existing.quantity >= existing.available_stock) {
                    this.showStockLimit(existing);
                    return;
                }
                existing.quantity += 1;
            }
            else this.cart.push({ ...product, quantity: 1, _discount: 0, _discountType: 'fixed', _unitPrice: '' });
            this.notice = '';
            this.closeResults();
            this.$nextTick(() => this.$refs.searchInput.focus());
        },
        closeResults() {
            this.query = '';
            this.results = [];
            this.selectedIndex = 0;
            this.requestNumber += 1;
        },
        increase(item) {
            if (item.controls_inventory && item.quantity >= item.available_stock) {
                this.showStockLimit(item);
                return;
            }
            item.quantity += 1;
            this.notice = '';
        },
        decrease(item) { if (item.quantity > 1) item.quantity -= 1; },
        remove(item) { this.cart = this.cart.filter(current => current.id !== item.id); },
        exceedsStock(item) { return item.controls_inventory && item.quantity > item.available_stock; },
        numberValue(value) {
            const number = Number(value);
            return Number.isFinite(number) ? number : 0;
        },
        decimal4(value) {
            return Math.round((this.numberValue(value) + Number.EPSILON) * 10000) / 10000;
        },
        customerPrice(item) {
    const level = this.selectedCustomer?.price_level || 'normal';

    const prices = {
        wholesale: item.wholesale_price,
        a: item.price_a,
        b: item.price_b,
        c: item.price_c,
    };

    const levelPrice = prices[level];

    if (
        level !== 'normal'
        && levelPrice !== null
        && levelPrice !== undefined
        && levelPrice !== ''
        && Number(levelPrice) > 0
    ) {
        return this.numberValue(levelPrice);
    }

    return this.numberValue(item.sale_price);
},
        lineAppliedPrice(item) {
    const manualPrice = this.numberValue(item._unitPrice);

    if (this.canOverridePrice && manualPrice > 0) {
        return manualPrice;
    }

    return this.customerPrice(item);
},
        lineGross(item) {
            return this.decimal4(this.lineAppliedPrice(item) * this.numberValue(item.quantity));
        },
        lineDiscount(item) {
            if (!this.canDiscount) return 0;
            const value = this.numberValue(item._discount);
            if (value <= 0) return 0;

            const gross = this.lineGross(item);
            if (item._discountType === 'percentage') {
                return this.decimal4(gross * (Math.min(value, 100) / 100));
            }

            return this.decimal4(Math.min(value, gross));
        },
        lineSubtotalBeforeGeneral(item) {
            return this.decimal4(this.lineGross(item) - this.lineDiscount(item));
        },
        lineGeneralDiscount(index) {
            return this.generalDiscountAllocations[index] ?? 0;
        },
        lineSubtotal(item, index) {
            return this.decimal4(this.lineSubtotalBeforeGeneral(item) - this.lineGeneralDiscount(index));
        },
        lineTax(item, index) {
            return this.decimal4(this.lineSubtotal(item, index) * (this.numberValue(item.tax_rate) / 100));
        },
        lineTotal(item, index) {
            return this.decimal4(this.lineSubtotal(item, index) + this.lineTax(item, index));
        },
        money(value) { return new Intl.NumberFormat('es-CR', { style: 'currency', currency: 'CRC', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(Number(value) || 0); },
        formatQuantity(value) { return new Intl.NumberFormat('es-CR', { maximumFractionDigits: 4 }).format(Number(value) || 0); },
        otherStockLabel(product) { return product.other_branch_stock.map(stock => `${stock.branch_name} ${this.formatQuantity(stock.available_stock)}`).join(', '); },
        showStockLimit(item) { this.notice = `Existencia máxima disponible: ${this.formatQuantity(item.available_stock)}`; },
        openImage(product) {
            if (!product.has_image || !product.image_url) return;
            this.imageModal = { open: true, url: product.image_url, name: product.name };
        },
        closeImage() { this.imageModal = { open: false, url: null, name: '' }; },
        handleGlobalEnter(event) {
            if (event.defaultPrevented || this.checkout.open || this.quickCustomer.open || this.resultsOpen || this.customerResultsOpen) return;
            if (['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON'].includes(event.target.tagName)) return;
            if (this.canCheckout) { event.preventDefault(); this.openCheckout(); }
        },
        openCheckout() {
            if (!this.canCheckout || this.checkout.processing) return;
            this.checkout.open = true;
            this.checkout.error = '';
            this.checkout.result = null;
            this.checkout.draft = { methodId: '', amount: String(this.pendingBalance || this.grandTotal), receivedAmount: String(this.pendingBalance || this.grandTotal), reference: '' };
            this.$nextTick(() => { this.$refs.checkoutAmount?.focus(); this.$refs.checkoutAmount?.select(); });
        },
        requestCloseCheckout() {
            if (this.checkout.processing || !this.checkout.open) return;
            if (this.checkout.payments.length && !window.confirm('¿Desea cerrar el cobro? Los pagos preparados se limpiarán.')) return;
            this.clearPayments();
            this.checkout.open = false;
            this.$nextTick(() => this.$refs.searchInput?.focus());
        },
        unsupportedPaymentMethod(method) { return ['credit', 'loyalty_points'].includes(method.type); },
        methodUnavailable(method) { return this.unsupportedPaymentMethod(method) || this.checkout.payments.some(payment => payment.payment_method_id === method.id) || this.pendingBalance <= 0; },
        methodInitial(method) { return (method.name || '?').trim().charAt(0).toUpperCase(); },
        selectPaymentMethod(method) {
            if (this.methodUnavailable(method) || this.checkout.processing) return;
            const amount = Number(this.checkout.draft.amount);
            if (!/^\d+$/.test(String(this.checkout.draft.amount)) || amount <= 0 || amount > this.pendingBalance) { this.checkout.error = 'Indique un monto entero que no supere el saldo pendiente.'; this.$refs.checkoutAmount?.focus(); return; }
            this.checkout.error = '';
            this.checkout.draft.methodId = method.id;
            this.checkout.draft.reference = '';
            this.checkout.draft.receivedAmount = String(amount);
            if (!method.requires_reference && !method.allows_change) { this.addPayment(); return; }
            this.$nextTick(() => { const field = method.requires_reference ? this.$refs.checkoutReference : this.$refs.receivedAmount; field?.focus(); field?.select?.(); });
        },
        usePendingBalance() { this.checkout.draft.amount = String(this.pendingBalance); this.checkout.draft.receivedAmount = String(this.pendingBalance); this.$nextTick(() => { this.$refs.checkoutAmount?.focus(); this.$refs.checkoutAmount?.select(); }); },
        cancelPaymentDraft() { this.checkout.draft = { methodId: '', amount: String(this.pendingBalance), receivedAmount: String(this.pendingBalance), reference: '' }; this.checkout.error = ''; this.$nextTick(() => { this.$refs.checkoutAmount?.focus(); this.$refs.checkoutAmount?.select(); }); },
        clearPayments() { this.checkout.payments = []; this.checkout.draft = { methodId: '', amount: String(this.grandTotal), receivedAmount: String(this.grandTotal), reference: '' }; this.checkout.error = ''; },
        handleCheckoutEnter(event) {
            if (event.defaultPrevented || !this.checkout.open || this.checkout.result || this.checkout.processing) return;
            if (this.selectedPaymentMethod) { if (this.canAddPayment) { event.preventDefault(); this.addPayment(); } return; }
            if (this.checkoutCanConfirm) { event.preventDefault(); this.confirmCheckout(); }
        },
        addPayment() {
            if (!this.canAddPayment) return;
            const method = this.selectedPaymentMethod, amount = Number(this.checkout.draft.amount), received = method.allows_change ? Number(this.checkout.draft.receivedAmount) : amount;
            this.checkout.payments.push({ payment_method_id: method.id, method_name: method.name, amount, received_amount: received, change_amount: method.allows_change ? received - amount : 0, reference: this.checkout.draft.reference.trim() || null });
            this.checkout.draft = { methodId: '', amount: String(this.pendingBalance), receivedAmount: String(this.pendingBalance), reference: '' };
            this.$nextTick(() => { this.$refs.checkoutAmount?.focus(); this.$refs.checkoutAmount?.select(); });
        },
        removePayment(index) { this.checkout.payments.splice(index, 1); this.checkout.error = ''; this.checkout.draft = { methodId: '', amount: String(this.pendingBalance), receivedAmount: String(this.pendingBalance), reference: '' }; },
        async confirmCheckout() {
            if (!this.checkoutCanConfirm) return;
            this.checkout.processing = true;
            this.checkout.error = '';
            try {
                const response = await fetch({{ Illuminate\Support\Js::from(route('pos.checkout')) }}, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({
                        checkout_token: this.checkoutToken,
                        cash_session_id: this.cashSessionId || null,
                        ...(this.quoteId ? { quote_id: this.quoteId } : {}),
                        ...(this.canDiscount && this.numberValue(this._generalDiscountInput) > 0 ? {
                            discount_total: this.numberValue(this._generalDiscountInput),
                            discount_total_type: this._generalDiscountType,
                        } : {}),
                        ...(this.suspended.activeId ? { suspended_sale_id: this.suspended.activeId, recovery_token: this.suspended.recoveryToken } : {}),
                        customer_id: this.customerId,
                        document_type: this.documentType,
                        payments: this.checkout.payments.map(({ payment_method_id, amount, received_amount, reference }) => ({ payment_method_id, amount, received_amount, reference })),
                        items: this.cart.map(item => ({
                            product_id: item.id,
                            quantity: item.quantity,
                            ...(this.canDiscount && this.numberValue(item._discount) > 0 ? {
                                discount: this.numberValue(item._discount),
                                discount_type: item._discountType,
                            } : {}),
                            ...(this.canOverridePrice && this.numberValue(item._unitPrice) > 0 ? {
                                unit_price: this.numberValue(item._unitPrice),
                            } : {}),
                        })),
                    }),
                });
                const payload = await this.readFetchResponse(response);
                this.checkout.result = payload;
                this.cart = [];
                this.customerId = null;
                this.selectedCustomer = null;
                this.clearQuote();
                if (this.documentType === 'electronic_invoice') {
    this.documentType = 'electronic_ticket';
}
                this.clearSuspendedRecovery();
                this.successMessage = payload.message;
            } catch (error) {
                this.checkout.error = error.message || 'No fue posible completar el cobro. Intente nuevamente.';
            } finally {
                this.checkout.processing = false;
            }
        },
        newSale() {
            this.checkoutToken = crypto.randomUUID();
            this.checkout = { open: false, processing: false, payments: [], draft: { methodId: '', amount: '', receivedAmount: '', reference: '' }, error: '', result: null };
            this._generalDiscountInput = '';
            this._generalDiscountType = 'fixed';
            this.successMessage = '';
            this.clearQuote();
            this.clearSuspendedRecovery();
            this.$nextTick(() => this.$refs.searchInput.focus());
        },
        clearSuspendedRecovery() { this.suspended.activeId = null; this.suspended.recoveryToken = null; this.suspended.warnings = []; this.suspended.customerInvalid = false; },
        async releaseCurrentRecovery() {
            if (!this.suspended.activeId) return true;
            try {
                const response = await fetch(`/pos/suspendidas/${this.suspended.activeId}/liberar`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ recovery_token: this.suspended.recoveryToken }) });
                await this.readFetchResponse(response);
                this.clearSuspendedRecovery();
                return true;
            } catch (error) {
                this.notice = error.message;
                this.suspended.error = error.message;
                return false;
            }
        },
        async clearCart() {
            if (!this.cart.length) return;
            if (this.suspended.activeId && !window.confirm('Este carrito proviene de una venta suspendida. Si lo limpia, la suspensión volverá a quedar disponible.')) return;
            if (!this.suspended.activeId && !window.confirm('¿Desea limpiar el carrito?')) return;
            if (this.suspended.activeId && !(await this.releaseCurrentRecovery())) return;
            this.cart = []; this.customerId = null; this.selectedCustomer = null; this.checkout.payments = []; this.notice = ''; this.checkoutToken = crypto.randomUUID(); this._generalDiscountInput = ''; this._generalDiscountType = 'fixed';
            this.$nextTick(() => this.$refs.searchInput.focus());
        },
        async suspendCurrent() {
            if (!this.cart.length || this.suspended.saving) return;
            this.suspended.saving = true; this.notice = '';
            try {
                const recoveredCart = this.suspended.activeId && this.suspended.recoveryToken;
                const url = recoveredCart ? `/pos/suspendidas/${this.suspended.activeId}/volver-a-suspender` : {{ Illuminate\Support\Js::from(route('pos.suspended.store')) }};
                const response = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ ...(recoveredCart ? { recovery_token: this.suspended.recoveryToken } : {}), customer_id: this.customerId, items: this.cart.map(item => ({ product_id: item.id, quantity: item.quantity })) }) });
                const payload = await this.readFetchResponse(response);
                this.cart = []; this.customerId = null; this.selectedCustomer = null; this.checkoutToken = crypto.randomUUID(); this.clearSuspendedRecovery(); this.successMessage = payload.message;
            } catch (error) { this.notice = error.message; } finally { this.suspended.saving = false; }
        },
        async openSuspended() {
            this.suspended.open = true; this.suspended.loading = true; this.suspended.error = '';
            try { const response = await fetch({{ Illuminate\Support\Js::from(route('pos.suspended.index')) }}, { headers: { Accept: 'application/json' } }); const payload = await this.readFetchResponse(response); this.suspended.list = payload; }
            catch (error) { this.suspended.error = error.message; } finally { this.suspended.loading = false; }
        },
        async recoverSuspended(sale) {
            if (this.cart.length && !window.confirm('El carrito actual será reemplazado. ¿Desea continuar?')) return;
            this.suspended.error = '';
            try {
                if (this.suspended.activeId && this.suspended.activeId !== sale.id && !(await this.releaseCurrentRecovery())) return;
                const response = await fetch(`/pos/suspendidas/${sale.id}/recuperar`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ recovery_token: this.suspended.activeId === sale.id ? this.suspended.recoveryToken : null }) });
                const payload = await this.readFetchResponse(response);
                this.cart = payload.items.map(item => ({ id: item.product_id, name: item.name, internal_code: item.code, barcode: item.barcode, quantity: Number(item.quantity), sale_price: Number(item.price), tax_rate: Number(item.tax_rate), available_stock: Number(item.stock), controls_inventory: !!item.track_inventory, allows_decimals: !!item.allows_decimals, has_image: !!item.image_url, image_url: item.image_url, unavailable: !!item.unavailable, _discount: 0, _discountType: 'fixed', _unitPrice: '' }));
                this.customerId = payload.customer?.id || null; this.selectedCustomer = payload.customer; this.suspended.activeId = payload.suspended_sale_id; this.suspended.recoveryToken = payload.recovery_token; this.suspended.warnings = payload.warnings || []; this.suspended.customerInvalid = !!payload.customer_invalid; this.notice = this.suspended.warnings.join(' '); this.checkoutToken = crypto.randomUUID(); this.checkout.payments = []; this.suspended.open = false; this.$nextTick(() => this.$refs.searchInput.focus());
            } catch (error) { this.suspended.error = error.message; }
        },
        async cancelSuspended(sale) {
            const reason = window.prompt(`Razón para cancelar ${sale.suspension_number}:`); if (!reason?.trim() || !window.confirm('La suspensión quedará cancelada. ¿Continuar?')) return;
            try { const response = await fetch(`/pos/suspendidas/${sale.id}/cancelar`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ reason: reason.trim() }) }); await this.readFetchResponse(response); this.suspended.list = this.suspended.list.filter(item => item.id !== sale.id); }
            catch (error) { this.suspended.error = error.message; }
        },
        async searchCustomers() {
            const term = this.customerQuery.trim();
            const currentRequest = ++this.customerRequestNumber;
            if (!term) {
                this.customerResults = [];
                this.customerLoading = false;
                return;
            }
            this.customerLoading = true;
            try {
                const url = new URL({{ Illuminate\Support\Js::from(route('pos.customers.search')) }}, window.location.origin);
                url.searchParams.set('q', term);
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('No fue posible buscar clientes.');
                const customers = await response.json();
                if (currentRequest !== this.customerRequestNumber) return;
                this.customerResults = customers;
                this.customerSelectedIndex = 0;
            } catch (error) {
                if (currentRequest === this.customerRequestNumber) this.customerResults = [];
            } finally {
                if (currentRequest === this.customerRequestNumber) this.customerLoading = false;
            }
        },
        moveCustomerSelection(direction) {
            if (!this.customerResults.length) return;
            this.customerSelectedIndex = (this.customerSelectedIndex + direction + this.customerResults.length) % this.customerResults.length;
        },
        selectMarkedCustomer() {
            if (this.customerResults[this.customerSelectedIndex]) this.selectCustomer(this.customerResults[this.customerSelectedIndex]);
        },
        selectCustomer(customer) {
    this.customerId = customer.id;
    this.selectedCustomer = customer;

    if (this.notice === 'Seleccione un cliente antes de usar Factura electrónica.') {
        this.notice = '';
    }

    this.closeCustomerResults();
},
        clearCustomer() {
            this.customerId = null;
            this.selectedCustomer = null;
            this.suspended.customerInvalid = false;
            this.closeCustomerResults();
            this.$nextTick(() => this.$refs.customerSearchInput.focus());
        },
        closeCustomerResults() {
            this.customerQuery = '';
            this.customerResults = [];
            this.customerSelectedIndex = 0;
            this.customerRequestNumber += 1;
        },
        openQuickCustomer() {
            this.quickCustomer.open = true;
            this.quickCustomer.errors = {};
            this.quickCustomer.message = '';
            this.$nextTick(() => this.$refs.quickCustomerName?.focus());
        },
        closeQuickCustomer() {
            if (this.quickCustomer.saving) return;
            this.quickCustomer.open = false;
            this.quickCustomer.errors = {};
            this.quickCustomer.message = '';
        },
        resetQuickCustomer() {
            this.quickCustomer.form = { name: '', customer_type: 'individual', identification_type: '', identification: '', phone: '', mobile: '', email: '' };
            this.quickCustomer.errors = {};
            this.quickCustomer.message = '';
        },
        async storeQuickCustomer() {
            if (this.quickCustomer.saving || !this.quickCustomer.form.name.trim()) return;
            this.quickCustomer.saving = true;
            this.quickCustomer.errors = {};
            this.quickCustomer.message = '';
            try {
                const response = await fetch({{ Illuminate\Support\Js::from(route('pos.customers.quick-store')) }}, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(this.quickCustomer.form),
                });
                const payload = await this.readFetchResponse(response);
                if (response.status === 422) {
                    this.quickCustomer.errors = payload.errors || {};
                    this.quickCustomer.message = payload.message || 'Revise la información ingresada.';
                    return;
                }
                if (!response.ok) throw new Error('No fue posible crear el cliente.');
                this.selectCustomer(payload.customer);
                this.successMessage = payload.message;
                this.quickCustomer.open = false;
                this.resetQuickCustomer();
            } catch (error) {
                this.quickCustomer.errors = error.payload?.errors || {};
                this.quickCustomer.message = error.message || 'No fue posible crear el cliente. Intente nuevamente.';
            } finally {
                this.quickCustomer.saving = false;
            }
        },
        async init() {
            const params = new URLSearchParams(window.location.search);
            const quoteId = params.get('quote');
            if (quoteId) {
                await this.loadQuote(Number(quoteId));
            }
        },
        async loadQuote(id) {
            if (!this.canLoadQuote(id)) return;
            this.notice = '';
            try {
                const response = await fetch(`/cotizaciones/${id}/cargar`, { headers: { Accept: 'application/json' } });
                const payload = await this.readFetchResponse(response);
                this.cart = payload.items.map(item => ({
                    id: item.product_id,
                    name: item.product_name,
                    internal_code: item.product_code,
                    barcode: item.barcode,
                    quantity: Number(item.quantity),
                    sale_price: Number(item.unit_price),
                    tax_rate: Number(item.tax_rate),
                    available_stock: null,
                    controls_inventory: false,
                    allows_decimals: true,
                    has_image: false,
                    image_url: null,
                    unavailable: false,
                    _discount: Number(item.discount_total),
                    _discountType: 'fixed',
                    _unitPrice: Number(item.unit_price),
                }));
                this.quoteId = id;
                this.activeQuote = payload.quote;
                if (payload.quote.customer_id) {
                    this.customerId = payload.quote.customer_id;
                    this.selectedCustomer = { id: payload.quote.customer_id, name: payload.quote.customer_name, price_level: 'normal' };
                }
                this.checkoutToken = crypto.randomUUID();
                this.checkout.payments = [];
                this.checkout.open = false;
                this.notice = `Cotización ${payload.quote.quote_number} cargada. Se cobrará con los valores de la cotización.`;
            } catch (error) {
                this.notice = error.message;
            }
        },
        canLoadQuote(id) {
            if (this.cart.length && this.quoteId !== id && !window.confirm('El carrito actual será reemplazado por la cotización. ¿Desea continuar?')) return false;
            return true;
        },
        async saveAsQuote() {
            if (!this.cart.length || this.savingQuote) return;
            this.savingQuote = true;
            this.notice = '';
            try {
                const response = await fetch({{ Illuminate\Support\Js::from(route('cotizaciones.store')) }}, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({
                        customer_id: this.customerId,
                        items: this.cart.map(item => ({
                            product_id: item.id,
                            quantity: item.quantity,
                            ...(this.canDiscount && this.numberValue(item._discount) > 0 ? { discount: this.numberValue(item._discount), discount_type: item._discountType } : {}),
                            ...(this.canOverridePrice && this.numberValue(item._unitPrice) > 0 ? { unit_price: this.numberValue(item._unitPrice) } : {}),
                        })),
                    }),
                });
                const payload = await this.readFetchResponse(response);
                this.successMessage = payload.message;
                this.notice = payload.message;
            } catch (error) {
                this.notice = error.message;
            } finally {
                this.savingQuote = false;
            }
        },
        clearQuote() {
            this.quoteId = null;
            this.activeQuote = null;
        },
    }));
});
</script>
@endpush
