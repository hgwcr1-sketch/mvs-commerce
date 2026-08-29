@extends('layouts.app')

@section('title', 'Punto de venta')

@section('content')
<div x-data="posTerminal"
     x-cloak
     @keydown.enter.window="handleGlobalEnter($event)"
     @mvs-scan.window="onMvsScan($event)"
     @mvs-scanner-change.window="cameraScannerOpen = $event.detail.open"
     class="space-y-3 text-sm">
    <section class="rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg">
        <div class="flex flex-col gap-2 xl:flex-row xl:items-center xl:justify-between">
            <div class="grid flex-1 grid-cols-2 gap-3 md:grid-cols-4">
                <div><p class="text-xs uppercase text-slate-400">Empresa</p><p class="font-semibold">{{ $company->trade_name }}</p></div>
                <div><p class="text-xs uppercase text-slate-400">Sucursal</p><p class="font-semibold">{{ $branch->name }}</p></div>
                <div><p class="text-xs uppercase text-slate-400">Cajero</p><p class="font-semibold">{{ $cashier->name }}</p></div>
                <div><p class="text-xs uppercase text-slate-400">Estado de caja</p><p class="font-semibold text-amber-400">@if($cashSession) Caja abierta: {{ $cashSession->session_number }} — {{ $cashSession->cashRegister->name }} @elseif($cashSettings->require_open_session) Debe abrir una caja antes de cobrar @else Sin apertura de caja — cobro permitido temporalmente @endif</p>@if(!$cashSession && $canOpenCash)<a href="{{ route('cash.open.create') }}" class="text-xs text-amber-300 underline">Abrir caja</a>@endif</div>
            </div>
            <a href="{{ route('dashboard') }}"
               class="self-end rounded-lg border border-slate-600 px-3 py-1.5 text-sm font-medium hover:bg-slate-800 xl:self-auto">
                Volver
            </a>
        </div>
    </section>

    @if($cashSettings->session_mode === \App\Models\CompanyCashSetting::SESSION_MODE_SHARED && $cashSessions->count() > 1)
        <section class="rounded-2xl border border-amber-300 bg-amber-50 p-4"><label for="cash-session" class="font-semibold text-amber-900">Caja / Sesión para cobrar</label><select id="cash-session" x-model="cashSessionId" class="mt-2 w-full rounded-xl border-amber-300"><option value="">Seleccione una sesión</option>@foreach($cashSessions as $session)<option value="{{ $session->id }}">{{ $session->session_number }} — {{ $session->cashRegister->name }}</option>@endforeach</select></section>
    @endif

    <section class="relative rounded-xl bg-white p-3 shadow-sm">
        <label for="pos-product-search" class="mb-1 block text-xs font-semibold uppercase text-slate-600">Agregar producto</label>
        <div class="flex items-center gap-2">
            <div class="relative flex-1">
                <input id="pos-product-search"
                       x-ref="searchInput"
                       x-model="query"
                       @input.debounce.180ms="searchProducts"
                       @keydown.down.prevent="moveSelection(1)"
                       @keydown.up.prevent="moveSelection(-1)"
                       @keydown.enter.prevent="addSelected"
                       @keydown.escape="closeResults"
                       type="search"
                       autocomplete="off"
                       placeholder="Buscar por nombre, código o escanear código de barras…"
                       class="w-full rounded-xl border-2 border-slate-300 bg-slate-50 px-4 py-2.5 text-base outline-none transition focus:border-amber-500 focus:bg-white focus:ring-2 focus:ring-amber-100">
                <span x-show="loading" class="absolute right-4 top-1/2 -translate-y-1/2 text-xs text-slate-500">Buscando…</span>
            </div>
            {{-- R02-B: escáner por cámara como capa de entrada adicional.
                 No sustituye búsqueda manual, teclado ni lectores HID. --}}
            <button type="button"
                    x-show="cameraScannerAvailable"
                    x-cloak
                    @click="$dispatch('mvs-scanner-open', { videoId: 'pos-scanner-video' })"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border-2 border-amber-400 bg-amber-50 text-amber-700 hover:bg-amber-100"
                    aria-label="Escanear código con cámara"
                    title="Escanear código con cámara">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8.25A2.25 2.25 0 0 1 5.25 6h1.4l1.13-1.69a.75.75 0 0 1 .62-.31h3.2a.75.75 0 0 1 .62.31L13.35 6h5.4A2.25 2.25 0 0 1 21 8.25v9a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 17.25v-9Z"/>
                    <circle cx="12" cy="12.75" r="3.25"/>
                </svg>
            </button>
        </div>



        <div x-show="resultsOpen" class="absolute z-[100] mt-2 max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-2xl" :style="dropdownPos($refs.searchInput, results.length)">
            <template x-for="(product, index) in results" :key="product.id">
                <button type="button"
                        @click="addProduct(product)"
                        @mouseenter="selectedIndex = index"
                        :disabled="!product.can_add_to_cart"
                        :class="selectedIndex === index ? 'bg-amber-50 ring-1 ring-inset ring-amber-300' : 'hover:bg-slate-50'"
                        class="grid w-full gap-2 border-b border-slate-100 px-3 py-2 text-left last:border-0 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:opacity-75 md:grid-cols-[2.75rem_minmax(0,1fr)_auto_auto] md:items-center">
                    <div class="flex h-11 w-11 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
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

    <div class="grid items-start gap-3 md:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] lg:grid-cols-4">
        <section class="overflow-hidden rounded-xl bg-white shadow-sm lg:col-span-3">
                <div class="border-b border-slate-200 px-4 py-2.5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-base font-bold text-slate-800">Carrito temporal</h2>
                        <button x-show="cart.length" type="button" @click="clearCart" class="rounded-lg px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50">Limpiar carrito</button>
                    </div>
                    <p x-show="notice" x-text="notice" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800"></p>
                </div>
                <div class="overflow-auto lg:max-h-[calc(100vh-18rem)]">
                    <table class="min-w-full">
                        <thead class="hidden bg-slate-100 text-xs uppercase text-slate-600 lg:sticky lg:top-0 lg:z-10 lg:table-header-group">
                            <tr>
                                <th class="min-w-56 px-3 py-2 text-left">Producto</th>
                                <th class="w-28 px-3 py-2 text-center">Cantidad</th>
                                <th class="w-28 px-3 py-2 text-right">Precio</th>
                                <th class="w-36 px-3 py-2 text-right">Descuento</th>
                                <th class="w-24 px-3 py-2 text-right">Impuesto</th>
                                <th class="w-28 px-3 py-2 text-right">Total</th>
                                <th class="w-20 px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, index) in cart" :key="item.id">
                                <tr class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 border-b border-slate-100 p-3 align-top last:border-0 lg:table-row lg:p-0" :class="exceedsStock(item) ? 'bg-red-50' : ''">
                                    <td class="order-1 col-span-2 flex items-start justify-between gap-2 lg:table-cell lg:min-w-56 lg:px-3 lg:py-2">
                                        <div class="flex items-center gap-2">
                                            <button type="button" @click="openImage(item)" :disabled="!item.has_image" class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 lg:h-9 lg:w-9">
                                                <img x-show="item.has_image" :src="item.image_url" :alt="item.name"
                                                     class="h-full w-full cursor-zoom-in object-contain p-1">
                                                <svg x-show="!item.has_image" class="h-6 w-6 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7.5 12 3 4 7.5m16 0v9L12 21m8-13.5-8 4.5m0 9-8-4.5v-9m8 13.5v-9M4 7.5l8 4.5"/>
                                                </svg>
                                            </button>
                                            <div>
                                                <p class="font-semibold text-slate-800" x-text="item.name"></p>
                                                <p class="text-xs text-slate-500" x-text="item.internal_code"></p>
                                                <span x-show="item.is_offer" class="mt-0.5 inline-block rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-700 lg:hidden">Oferta</span>
                                                <p x-show="exceedsStock(item)" class="mt-1 text-xs font-semibold text-red-600">Cantidad superior al stock de esta sucursal.</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="order-2 flex flex-col gap-1 rounded-xl bg-slate-50 px-3 py-2 sm:flex-row sm:items-center sm:justify-between lg:table-cell lg:w-28 lg:bg-transparent lg:px-0 lg:py-0 lg:whitespace-nowrap">
                                        <span class="text-xs font-semibold uppercase text-slate-500 sm:hidden">Cantidad</span>
                                        <div class="flex items-center justify-center gap-2">
                                            <button type="button" @click="decrease(item)" class="h-11 w-11 shrink-0 rounded-md bg-slate-200 text-base font-bold hover:bg-slate-300 disabled:opacity-40 lg:h-7 lg:w-7">−</button>
                                            <input type="number"
                                                   x-model.number="item.quantity"
                                                   :min="item.allows_decimals ? 0.0001 : 1"
                                                   :step="item.allows_decimals ? 0.0001 : 1"
                                                   inputmode="decimal"
                                                   enterkeyhint="done"
                                                   class="h-11 w-20 shrink-0 rounded border border-slate-300 px-2 text-center text-sm font-bold lg:h-auto lg:w-16 lg:py-1">
                                            <button type="button" @click="increase(item)" class="h-11 w-11 shrink-0 rounded-md bg-amber-500 text-base font-normal text-black hover:bg-amber-600 disabled:opacity-40 lg:h-7 lg:w-7">+</button>
                                        </div>
                                    </td>
                                    <td class="order-4 flex items-start justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 text-right lg:table-cell lg:w-28 lg:bg-transparent lg:px-0 lg:py-0 lg:whitespace-nowrap">
                                        <span class="text-left text-xs font-semibold uppercase text-slate-500 lg:hidden">
                                            Precio
                                            <span x-show="item.is_offer" class="block font-bold normal-case text-emerald-600">Oferta</span>
                                        </span>
                                        <template x-if="canOverridePrice">
                                            <div class="flex flex-col items-end gap-1">
                                                <input x-model="item._unitPrice"
                                                       type="number"
                                                       min="1"
                                                       step="1"
                                                       inputmode="numeric"
                                                        class="h-11 w-24 rounded border border-amber-300 bg-amber-50 px-2 text-right text-sm font-bold text-amber-900 lg:h-auto lg:py-1"
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
                                    <td class="order-6 col-span-2 flex items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 lg:table-cell lg:w-36 lg:bg-transparent lg:px-0 lg:py-0 lg:whitespace-nowrap">
                                        <span class="text-xs font-semibold uppercase text-slate-500 lg:hidden">Descuento</span>
                                        <template x-if="canDiscount">
                                            <div class="flex items-center justify-end gap-1">
                                                <select x-model="item._discountType"
                                                        class="h-11 w-14 rounded border border-amber-300 bg-amber-50 px-1 text-xs font-bold text-amber-900 lg:h-auto lg:py-1">
                                                    <option value="fixed">₡</option>
                                                    <option value="percentage">%</option>
                                                </select>
                                                <input x-model="item._discount"
                                                       type="number"
                                                       min="0"
                                                       :step="item._discountType === 'fixed' ? 1 : 0.0001"
                                                       inputmode="decimal"
                                                       class="h-11 w-24 rounded border border-amber-300 bg-amber-50 px-2 text-right text-sm lg:h-auto lg:py-1"
                                                       placeholder="0">
                                            </div>
                                        </template>

                                    </td>
                                    <td class="order-5 flex items-start justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 text-right lg:table-cell lg:w-24 lg:bg-transparent lg:px-0 lg:py-0 lg:whitespace-nowrap">
                                        <span class="text-xs font-semibold uppercase text-slate-500 lg:hidden">Impuesto</span>
                                        <span x-text="money(lineTax(item, index))"></span>
                                    </td>
                                    <td class="order-3 flex items-start justify-between gap-2 text-right lg:table-cell lg:w-28 lg:px-0 lg:py-0 lg:whitespace-nowrap">
                                        <span class="text-xs font-semibold uppercase text-slate-500 lg:hidden">Total</span>
                                        <strong class="text-base font-bold lg:text-sm" x-text="money(lineTotal(item, index))"></strong>
                                    </td>
                                    <td class="order-7 flex items-center justify-end lg:table-cell lg:w-20 lg:px-0 lg:py-0 lg:whitespace-nowrap lg:text-right"><button type="button" @click="remove(item)" class="min-h-[44px] rounded-lg px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 disabled:opacity-40 lg:min-h-0 lg:px-2 lg:py-1 lg:text-xs lg:font-normal">Eliminar</button></td>
                                </tr>
                            </template>
                            <tr x-show="cart.length === 0">
                                <td colspan="7" class="py-3 text-center text-sm text-slate-500">Busque o escanee un producto para iniciar.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
        </section>

        <aside class="space-y-3 md:sticky md:top-3">
            <section class="relative rounded-xl bg-white p-3 shadow-sm">
                <p x-show="successMessage" x-text="successMessage" class="mb-2 rounded-lg bg-green-50 px-3 py-2 text-xs font-semibold text-green-700"></p>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-500">Cliente</p>
                        <p class="font-bold text-slate-800" x-text="selectedCustomer ? selectedCustomer.name : 'Consumidor Final'"></p>
                        <p x-show="selectedCustomer" class="text-xs text-slate-500">
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

                <div class="mt-2">
                    <label for="pos-customer-search" class="mb-1 block text-xs font-semibold text-slate-700">Buscar cliente</label>
                    <div class="flex items-center gap-2">
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
                               placeholder="Nombre, identificación, teléfono, correo o cód. público…"
                               class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-amber-500 focus:ring-0">
                        <button type="button"
                                x-show="cameraScannerAvailable"
                                x-cloak
                                @click="$dispatch('mvs-scanner-open', { videoId: 'pos-scanner-video' })"
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-600 hover:bg-slate-100"
                                aria-label="Escanear QR del cliente"
                                title="Escanear QR/Code128 del cliente">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8.25A2.25 2.25 0 0 1 5.25 6h1.4l1.13-1.69a.75.75 0 0 1 .62-.31h3.2a.75.75 0 0 1 .62.31L13.35 6h5.4A2.25 2.25 0 0 1 21 8.25v9a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 17.25v-9Z"/>
                                <circle cx="12" cy="12.75" r="3.25"/>
                            </svg>
                        </button>
                    </div>
                </div>



                <div x-show="customerResultsOpen"
                     class="absolute z-[100] mt-2 max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-2xl" :style="dropdownPos($refs.customerSearchInput, customerResults.length)">
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

            <section class="rounded-xl bg-white p-3 shadow-sm">
                <h2 class="text-sm font-bold text-slate-800">Formas de pago disponibles</h2>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @forelse($paymentMethods as $paymentMethod)
                        <span class="rounded-full border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800">{{ $paymentMethod->name }}</span>
                    @empty
                        <span class="text-sm text-slate-500">No hay formas de pago activas.</span>
                    @endforelse
                </div>
            </section>

            <section class="rounded-xl bg-slate-900 p-4 text-white shadow-lg">
                <div class="space-y-2 text-sm">
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
                                       :step="_generalDiscountType === 'fixed' ? 1 : 0.0001"
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
                <div class="my-3 border-t border-slate-700"></div>
                <div class="flex items-end justify-between"><span class="text-base">Total</span><strong class="text-2xl text-amber-400" x-text="money(grandTotal)"></strong></div>
                @can('ventas.crear')
                    <button type="button" @click="openCheckout" :disabled="!canCheckout"
                            class="mt-3 w-full rounded-xl bg-amber-500 px-4 py-2.5 text-base font-normal text-black hover:bg-amber-600 disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-400">
                        Cobrar
                    </button>
                @endcan
            </section>
        </aside>
    </div>

    {{-- R02-A: barra sticky Total/Cobrar (móvil/tablet). Debajo de los modales (z<100),
         oculta con carrito vacío, durante el cobro o con cualquier modal POS abierto,
         y se desplaza fuera de vista cuando el teclado móvil recibe el foco. --}}
    <div id="pos-sticky-bar"
         x-cloak
         x-show="cart.length > 0 && !checkout.open && !orderRequest.open && !suspended.open && !imageModal.open && !quickCustomer.open && !cameraScannerOpen"
         class="fixed inset-x-0 bottom-0 z-[90] border-t border-slate-200 bg-white shadow-[0_-8px_24px_rgba(15,23,42,0.18)] transition-transform duration-200 lg:hidden"
         style="padding-bottom: env(safe-area-inset-bottom);">

        <div class="flex items-center justify-between gap-3 px-4 py-3">
            <div class="leading-tight">
                <p class="text-xs font-semibold uppercase text-slate-500">Total</p>
                <p class="text-xl font-black text-slate-900" x-text="money(grandTotal)"></p>
            </div>
            @can('ventas.crear')
                <button type="button" @click="openCheckout" :disabled="!canCheckout"
                        class="max-w-[16rem] min-h-[48px] flex-1 rounded-xl bg-amber-500 px-6 text-base font-bold text-black hover:bg-amber-600 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500">
                    Cobrar
                </button>
            @endcan
        </div>
    </div>

    <section class="rounded-xl bg-white p-2 shadow-sm">
        <div class="flex gap-1.5 overflow-x-auto">

      <button
    type="button"
    @click.prevent.stop="
        documentType = 'electronic_ticket';
        notice = '';
    "
    :class="documentType === 'electronic_ticket'
        ? 'bg-amber-500 text-black'
        : 'border border-slate-300 bg-white text-slate-700'"
    class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-normal">
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
    class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-normal hover:bg-amber-100">
    Factura electrónica
</button>

            <button type="button" @click="suspendCurrent" :disabled="cart.length === 0 || suspended.saving" x-text="suspended.activeId && suspended.recoveryToken ? 'Volver a suspender' : 'Suspender'" class="whitespace-nowrap rounded-lg border border-amber-400 px-3 py-2 text-sm font-bold text-amber-800 disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"></button>
            <button type="button" @click="openSuspended" class="whitespace-nowrap rounded-lg bg-slate-800 px-3 py-2 text-sm font-bold text-white">Suspendidas</button>
            @can('cotizaciones.crear')<button type="button" @click="createQuote()" :disabled="cart.length === 0 || quoteId || creatingQuote" class="whitespace-nowrap rounded-lg border border-sky-500 px-3 py-2 text-sm font-bold text-sky-700 disabled:opacity-40" x-text="creatingQuote ? 'Cotizando…' : 'Cotizar'">Cotizar</button>@endcan
            @can('apartados.crear')<a href="{{ route('apartados.create') }}" class="whitespace-nowrap rounded-lg border border-amber-500 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-50">Nuevo apartado</a>@endcan
            @can('pedidos.crear')<button type="button" data-testid="create-internal-order" @click="openOrderRequest" class="whitespace-nowrap rounded-lg border border-emerald-500 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-50">Solicitar reposición</button>@endcan
            @foreach(['Nota de crédito', 'Nota de débito'] as $option)
                <button type="button" disabled class="whitespace-nowrap rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 text-xs font-medium text-slate-400" title="Próximamente">{{ $option }} · Próximamente</button>
            @endforeach
        </div>
    </section>

    @can('pedidos.crear')
    <div x-show="orderRequest.open" x-cloak @click.self="closeOrderRequest" @keydown.escape.window="closeOrderRequest" @keydown.enter.stop
         class="fixed inset-0 z-[125] flex items-center justify-center bg-slate-950/80 p-3 sm:p-5" role="dialog" aria-modal="true" aria-label="Crear pedido interno">
        <div class="flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
            <header class="flex items-center justify-between gap-4 bg-slate-900 px-5 py-4 text-white sm:px-7">
                <div><h2 class="text-2xl font-black">Solicitar reposición</h2><p class="mt-1 text-sm text-slate-300">Solicitud de productos para la sucursal activa</p></div>
                <button type="button" @click="closeOrderRequest" :disabled="orderRequest.saving" class="flex h-11 w-11 items-center justify-center rounded-full border border-white/25 text-2xl" aria-label="Cerrar pedido">×</button>
            </header>
            <template x-if="orderRequest.result">
                <div class="overflow-y-auto p-8 text-center sm:p-12">
                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-3xl font-black text-emerald-700">✓</div>
                    <h3 class="mt-4 text-2xl font-black text-slate-900" x-text="orderRequest.result.message"></h3>
                    <p class="mt-2 text-slate-600">El pedido quedó pendiente para revisión. No afectó inventario ni caja.</p>
                    <div class="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                        <button type="button" @click="closeOrderRequest" class="rounded-xl border border-slate-300 px-5 py-3 font-bold">Cerrar</button>
                        <a :href="orderRequest.result.show_url" class="rounded-xl bg-emerald-600 px-5 py-3 font-bold text-white hover:bg-emerald-700">Ver pedido</a>
                    </div>
                </div>
            </template>
            <template x-if="!orderRequest.result">
                <div class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
                        <div class="relative">
                            <label for="order-product-search" class="mb-1 block font-bold text-slate-800">Buscar producto</label>
                            <input id="order-product-search" x-ref="orderProductSearch" x-model="orderRequest.query" @input.debounce.250ms="searchOrderProducts" autocomplete="off" placeholder="Nombre, código o código de barras" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-emerald-500 focus:ring-emerald-500">
                            <div x-show="orderRequest.query.trim()" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl">
                                <p x-show="orderRequest.loading" class="p-4 text-slate-500">Buscando…</p>
                                <template x-for="product in orderRequest.results" :key="product.id">
                                    <button type="button" @click="addOrderProduct(product)" class="flex w-full items-center justify-between gap-4 border-b border-slate-100 p-4 text-left hover:bg-emerald-50">
                                        <span class="min-w-0"><strong class="block truncate" x-text="product.name"></strong><small class="text-slate-500" x-text="`${product.internal_code || 'Sin código'} · ${product.unit || 'Unidad'}`"></small></span>
                                        <span class="shrink-0 text-right text-xs"><span class="block" x-text="`Existencia: ${formatQuantity(product.available_stock)}`"></span><strong class="text-emerald-700" x-text="money(product.sale_price)"></strong></span>
                                    </button>
                                </template>
                                <p x-show="!orderRequest.loading && orderRequest.results.length === 0" class="p-4 text-slate-500">No se encontraron productos.</p>
                            </div>
                        </div>
                        <p x-show="orderRequest.error" x-text="orderRequest.error" class="mt-4 rounded-xl bg-red-50 p-3 font-semibold text-red-700"></p>
                        <div class="mt-5 space-y-3">
                            <p class="font-bold text-slate-700" x-text="`Productos solicitados: ${orderRequest.items.length}`"></p>
                            <p x-show="orderRequest.items.length === 0" class="rounded-2xl border-2 border-dashed border-slate-300 p-8 text-center text-slate-500">Busque y agregue al menos un producto.</p>
                            <template x-for="(item, index) in orderRequest.items" :key="item.id">
                                <article class="grid gap-3 rounded-2xl border border-slate-200 p-4 lg:grid-cols-[1.5fr_.7fr_.7fr_.65fr_1.3fr_auto] lg:items-end">
                                    <div><span class="text-xs font-bold uppercase text-slate-500">Producto</span><strong class="block" x-text="item.name"></strong><small class="text-slate-500" x-text="`${item.internal_code || 'Sin código'} · ${item.unit || 'Unidad'}`"></small></div>
                                    <div><span class="text-xs font-bold uppercase text-slate-500">Existencia actual</span><strong class="block" x-text="formatQuantity(item.available_stock)"></strong></div>
                                    <div><span class="text-xs font-bold uppercase text-slate-500">Precio de venta</span><strong class="block" x-text="money(item.sale_price)"></strong></div>
                                    <label class="text-xs font-bold uppercase text-slate-500">Cantidad solicitada<input type="number" x-model="item.requested_quantity" :step="item.allows_decimals ? 0.0001 : 1" :min="item.allows_decimals ? 0.0001 : 1" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-base font-normal text-slate-900"></label>
                                    <label class="text-xs font-bold uppercase text-slate-500">Observación<input type="text" x-model="item.request_note" maxlength="1000" placeholder="Opcional" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-base font-normal text-slate-900"></label>
                                    <button type="button" @click="removeOrderProduct(index)" class="rounded-lg px-3 py-2 font-bold text-red-600 hover:bg-red-50" aria-label="Eliminar producto">Eliminar</button>
                                </article>
                            </template>
                        </div>
                        <label class="mt-5 block font-bold text-slate-800">Observación general<textarea x-model="orderRequest.notes" maxlength="2000" rows="3" placeholder="Opcional" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal"></textarea></label>
                    </div>
                    <footer class="flex flex-col-reverse gap-3 border-t border-slate-200 p-4 sm:flex-row sm:justify-end sm:px-6">
                        <button type="button" @click="closeOrderRequest" :disabled="orderRequest.saving" class="rounded-xl border border-slate-300 px-5 py-3 font-bold">Cancelar</button>
                        <button type="button" @click="submitOrderRequest" :disabled="orderRequest.saving || orderRequest.items.length === 0" class="rounded-xl bg-emerald-600 px-6 py-3 font-bold text-white hover:bg-emerald-700 disabled:opacity-40" x-text="orderRequest.saving ? 'Enviando…' : 'Enviar solicitud'"></button>
                    </footer>
                </div>
            </template>
        </div>
    </div>
    @endcan

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
                    <div x-show="creditPaymentSelected" class="border-b px-5 py-4" :class="creditEligible ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50'">
                        <div class="grid gap-x-5 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <span>Cliente: <strong x-text="selectedCustomer?.name || 'Sin cliente seleccionado'"></strong></span>
                            <span>Límite de crédito: <strong x-text="money(selectedCustomer?.credit_limit || 0)"></strong></span>
                            <span>Utilizado: <strong x-text="money(selectedCustomer?.credit_used || 0)"></strong></span>
                            <span>Disponible: <strong x-text="money(creditAvailable)"></strong></span>
                            <span>Plazo: <strong x-text="`${selectedCustomer?.credit_days || 0} días`"></strong></span>
                            <span>Vencimiento: <strong x-text="creditDueDateLabel"></strong></span>
                            <span>Monto de esta venta: <strong x-text="money(grandTotal)"></strong></span>
                        </div>
                        <p class="mt-3 text-sm font-semibold" :class="creditEligible ? 'text-emerald-700' : 'text-red-700'" x-text="creditStatusMessage"></p>
                    </div>

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
                                    <div x-show="selectedCustomer" x-transition class="mt-3 rounded-2xl border border-[#B9BDC2] bg-white p-4">
                                        <div class="flex items-center justify-between gap-3"><h4 class="text-sm font-black text-[#111111]">Puntos de fidelización</h4><span x-show="loyalty.loading" class="text-xs font-semibold text-slate-400">Consultando…</span></div>
                                        <p x-show="!loyalty.loading && loyaltyBlockedReason" class="mt-1 text-xs font-medium text-slate-500" x-text="loyaltyBlockedReason"></p>
                                        <div x-show="!loyalty.loading && loyaltyUsable" class="mt-3 space-y-3">
                                            <div class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-2"><p class="font-semibold uppercase text-slate-400">Saldo</p><p class="mt-0.5 font-bold text-slate-800"><span x-text="formatPoints(loyalty.available_points)"></span> pts</p></div>
                                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-2"><p class="font-semibold uppercase text-slate-400">Valor</p><p class="mt-0.5 font-bold text-slate-800" x-text="money2(loyalty.available_money)"></p></div>
                                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-2"><p class="font-semibold uppercase text-slate-400">Máximo utilizable</p><p class="mt-0.5 font-bold text-slate-800" x-text="money2(loyalty.max_redeemable_money)"></p></div>
                                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-2"><p class="font-semibold uppercase text-slate-400">Mínimo requerido</p><p class="mt-0.5 font-bold text-slate-800" x-text="loyalty.minimum_enabled ? money2(loyalty.minimum_amount) : 'No aplica'"></p></div>
                                            </div>
                                            <div class="grid items-start gap-3 sm:grid-cols-2">
                                                <label class="block text-xs font-semibold uppercase text-slate-500">Usar puntos
                                                    <input type="number" min="0" step="0.0001" inputmode="decimal" placeholder="0" x-model="loyalty.requested" :disabled="checkout.processing" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-right text-base font-bold text-[#111111] focus:border-[#B1922D] focus:ring-2 focus:ring-amber-500/30 focus:outline-none">
                                                </label>
                                                <div class="space-y-1 rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs">
                                                    <div class="flex justify-between"><span class="text-slate-500">Valor del canje</span><strong class="text-slate-800" x-text="money2(loyaltyRedeemedEstimate)"></strong></div>
                                                    <div class="flex justify-between"><span class="text-slate-500">Pendiente por pagar</span><strong class="text-slate-800" x-text="money2(pendingBalance)"></strong></div>
                                                    <p x-show="loyaltyFractionalPending" class="font-semibold text-red-600">El canje genera un pendiente con centavos. Ajuste los puntos a un valor exacto en colones.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
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

                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" x-model="quickCustomer.form.create_portal_access" class="rounded border-slate-300">
                            <span class="text-sm font-semibold text-slate-700">Crear acceso al Portal de Cliente</span>
                        </label>
                        <p class="mt-1 text-xs text-slate-500">Se usará teléfono normalizado o email como usuario. Si no hay teléfono ni email válido, no se creará acceso.</p>
                        <p x-show="quickCustomer.errors.create_portal_access" x-text="quickCustomer.errors.create_portal_access?.[0]" class="mt-1 text-xs text-red-600"></p>
                    </div>
                </div>

                <!-- P08 Entrega: solo tras creación con portal_access.created -->
                <div x-show="quickCustomer.delivery" x-cloak class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-bold text-emerald-900">Acceso al Portal creado — entrégalo al cliente</h3>
                            <p class="mt-1 text-xs text-emerald-700">Contraseña temporal visible <strong>solo una vez</strong>.</p>
                        </div>
                        <button type="button" @click="quickCustomer.delivery = null" class="flex h-8 w-8 items-center justify-center rounded-full bg-white text-lg leading-none text-slate-600">×</button>
                    </div>
                    <div class="mt-3 grid gap-2">
                        <div class="rounded-xl bg-white p-3">
                            <p class="text-xs font-semibold uppercase text-slate-500">URL del Portal</p>
                            <a :href="quickCustomer.delivery?.portal_url" target="_blank" rel="noopener" class="break-all text-sm font-semibold text-emerald-700 underline" x-text="quickCustomer.delivery?.portal_url"></a>
                        </div>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div class="rounded-xl bg-white p-3">
                                <p class="text-xs font-semibold uppercase text-slate-500">Usuario</p>
                                <p class="mt-1 text-sm font-bold text-slate-900" x-text="quickCustomer.delivery?.username"></p>
                            </div>
                            <div class="rounded-xl bg-amber-50 p-3 ring-1 ring-amber-200">
                                <p class="text-xs font-semibold uppercase text-amber-800">Contraseña temporal</p>
                                <p class="mt-1 font-mono text-sm font-bold text-slate-900" x-text="quickCustomer.delivery?.password"></p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                        <button type="button" @click="copyPortalAccess()" class="min-h-11 flex-1 rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">Copiar acceso</button>
                        <a :href="quickCustomer.delivery?.whatsapp_url" x-show="quickCustomer.delivery?.whatsapp_url" target="_blank" rel="noopener" class="min-h-11 flex flex-1 items-center justify-center rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700">WhatsApp</a>
                        <span x-show="!quickCustomer.delivery?.whatsapp_url" class="flex flex-1 items-center justify-center rounded-xl bg-slate-200 px-4 py-3 text-sm font-semibold text-slate-500">WhatsApp no disponible</span>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" @click="closeQuickCustomer" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700">Cancelar</button>
                    <button type="submit" x-show="!quickCustomer.delivery" :disabled="quickCustomer.saving || !quickCustomer.form.name.trim()"
                            class="rounded-xl bg-amber-500 px-5 py-3 font-normal text-black hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-50">
                        <span x-text="quickCustomer.saving ? 'Guardando…' : 'Guardar cliente'"></span>
                    </button>
                    <button type="button" x-show="quickCustomer.delivery" @click="confirmPortalDelivery()" class="rounded-xl bg-amber-500 px-5 py-3 font-normal text-black hover:bg-amber-600">Continuar</button>
                </div>
            </form>
        </div>
    @endcan

    {{-- R02-B: hoja del escáner por cámara (capa reutilizable; emite mvs-scan). --}}
    <x-scanner.mvs-scanner />
</div>
@endsection

@push('scripts')
<script>
function generateUUID() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = Math.random() * 16 | 0;
        return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
}
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
        checkoutToken: generateUUID(),
        quoteId: null,
        creatingQuote: false,
        orderRequest: { open: false, saving: false, query: '', results: [], loading: false, requestNumber: 0, items: [], notes: '', error: '', result: null },
        cashSessionId: @json($cashSession?->id),
        cashSessionRequired: @json($cashSettings->require_open_session || ($cashSettings->session_mode === \App\Models\CompanyCashSetting::SESSION_MODE_SHARED && $cashSessions->count() > 1)),
        paymentMethods: @json($paymentMethods->values()),
        checkout: { open: false, processing: false, payments: [], draft: { methodId: '', amount: '', receivedAmount: '', reference: '' }, error: '', result: null },
        quickCustomer: {
            open: false,
            saving: false,
            errors: {},
            message: '',
            delivery: null,
            form: { name: '', customer_type: 'individual', identification_type: '', identification: '', phone: '', mobile: '', email: '', create_portal_access: false },
        },
        suspended: { open: false, loading: false, saving: false, list: [], error: '', activeId: null, recoveryToken: null, warnings: [], customerInvalid: false, canCancel: @json($canCancelSuspended) },
        loyaltyRequestNumber: 0,
        loyalty: { loading: false, available: false, reason: '', balance_points: '0', point_value: '1', minimum_enabled: false, minimum_amount: '0', eligible: false, available_points: '0', available_money: '0', maximum_redemption_percent: '100', max_redeemable_money: '0', max_redeemable_points: '0', offers_allowed: true, requested: '' },
        // R02-B: escáner por cámara (capa de entrada; sin estado propio de carrito).
        cameraScannerAvailable: false,
        cameraScannerOpen: false,


        init() {
            this.focusSearch();
            this.cameraScannerAvailable = window.mvsScannerAvailable === true;
            const quoteId = new URLSearchParams(window.location.search).get('quote_id');
            if (quoteId) this.loadQuote(quoteId);
            this.$watch('customerId', () => this.refreshLoyalty());
            this.$watch('grandTotal', () => { if (this.customerId) this.refreshLoyalty(); });
            const closeDropdownsOnScroll = () => {
                if (this.resultsOpen && document.activeElement !== this.$refs.searchInput) this.closeResults();
                if (this.customerResultsOpen && document.activeElement !== this.$refs.customerSearchInput) this.closeCustomerResults();
            };
            window.addEventListener('scroll', closeDropdownsOnScroll, { passive: true, capture: true });
        },

        // R02-A: solo se enfoca el buscador con puntero fino (desktop/lector HID).
        // En táctil evita abrir el teclado automáticamente al cargar o tras acciones.
        focusSearch() {
            if (! window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
            this.$refs.searchInput?.focus();
        },

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
        get availablePaymentMethods() { return this.paymentMethods.filter(method => method.type !== 'loyalty_points' && !this.checkout.payments.some(payment => payment.payment_method_id === method.id)); },
        get creditAvailable() { return Math.max(0, Number(this.selectedCustomer?.credit_limit || 0) - Number(this.selectedCustomer?.credit_used || 0)); },
        get creditPaymentSelected() { return this.selectedPaymentMethod?.type === 'credit' || this.checkout.payments.some(payment => payment.method_type === 'credit'); },
        get creditEligible() { return !!this.selectedCustomer && Number(this.selectedCustomer.credit_limit) > 0 && Number(this.selectedCustomer.credit_days) > 0 && this.creditAvailable >= this.grandTotal; },
        get creditDueDateLabel() { const value = this.selectedCustomer?.credit_due_date; return value ? new Date(`${value}T00:00:00`).toLocaleDateString('es-CR') : '—'; },
        get creditStatusMessage() {
            if (!this.selectedCustomer) return 'Para vender a crédito debe seleccionar un cliente.';
            if (Number(this.selectedCustomer.credit_limit) <= 0) return 'Este cliente no tiene crédito autorizado.';
            if (Number(this.selectedCustomer.credit_days) <= 0) return 'El cliente no tiene un plazo de crédito configurado.';
            if (this.creditAvailable < this.grandTotal) return 'El cliente no tiene crédito disponible suficiente.';
            return 'Crédito disponible suficiente para esta venta.';
        },
        get canCheckout() { return this.cart.length > 0 && (!this.cashSessionRequired || !!this.cashSessionId) && !this.suspended.customerInvalid && !this.hasInvalidAdjustments && this.grandTotal > 0 && !this.cart.some(item => item.unavailable || this.exceedsStock(item)) && this.availablePaymentMethods.length > 0; },
        get selectedPaymentMethod() { return this.paymentMethods.find(method => method.id === Number(this.checkout.draft.methodId)); },
        get appliedTotal() { return this.checkout.payments.reduce((sum, payment) => sum + Number(payment.amount), 0); },
        get pendingBalance() { return Math.max(0, Math.round(this.grandTotal - this.appliedTotal - this.loyaltyRedeemedEstimate)); },
        get loyaltyRequestedPoints() {
            const value = Number(this.loyalty.requested);
            if (!this.loyalty.available || !Number.isFinite(value) || value <= 0) return 0;
            const max = Number(this.loyalty.max_redeemable_points);
            if (Number.isFinite(max) && max > 0) return Math.min(value, max);
            return value;
        },
        get loyaltyRedeemedEstimate() { return this.decimal4(this.loyaltyRequestedPoints * this.numberValue(this.loyalty.point_value)); },
        get loyaltyUsable() { return this.loyalty.available && this.loyalty.eligible && this.loyalty.offers_allowed && Number(this.loyalty.max_redeemable_points) > 0; },
        get loyaltyBlockedReason() {
            if (!this.selectedCustomer) return 'Seleccione un cliente para consultar sus puntos.';
            if (this.loyalty.loading) return '';
            if (!this.loyalty.available) {
                if (this.loyalty.reason === 'no_account') return 'El cliente no tiene cuenta de Fidelización.';
                if (this.loyalty.reason === 'inactive') return 'Fidelización está desactivada para esta empresa.';
                if (this.loyalty.reason === 'invalid_configuration') return 'La configuración de Fidelización no es válida.';
                return 'Puntos no disponibles para este cliente.';
            }
            if (!this.loyalty.offers_allowed) return 'El canje de puntos no está permitido en ofertas.';
            if (!this.loyalty.eligible) return 'El saldo no alcanza el mínimo requerido para canjear.';
            return '';
        },
        get loyaltyFractionalPending() {
            if (this.loyaltyRequestedPoints <= 0) return false;
            const pending = this.grandTotal - this.appliedTotal - this.loyaltyRedeemedEstimate;
            return Math.abs(pending - Math.round(pending)) > 0.000001;
        },
        get totalPaymentChange() { return this.checkout.payments.reduce((sum, payment) => sum + Number(payment.change_amount), 0); },
        get checkoutCanConfirm() { return !this.checkout.processing && this.checkout.payments.length > 0 && this.pendingBalance === 0 && !this.loyaltyFractionalPending && (!this.creditPaymentSelected || this.creditEligible); },
        get checkoutError() { return this.checkout.error; },
        get canAddPayment() {
            const method = this.selectedPaymentMethod, amount = Number(this.checkout.draft.amount);
            if (!method || this.checkout.processing || this.methodUnavailable(method) || !/^\d+$/.test(String(this.checkout.draft.amount)) || amount <= 0 || amount > this.pendingBalance) return false;
            if (method.type === 'credit' && !this.creditEligible) return false;
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
                const url = new URL({{ Illuminate\Support\Js::from(route('pos.products.search', [], false)) }}, window.location.origin);
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
        // P09C + R02-B: escáner reutilizable para productos y cliente por código público.
        // Si el código parece public_code (6-12 alfanum), intenta seleccionar cliente exacto; si no, cae a producto.
        async onMvsScan(event) {
            const code = String(event?.detail?.code ?? '').trim();
            if (!code) return;
            if (/^[A-Z0-9]{6,12}$/i.test(code)) {
                try {
                    const url = new URL({{ Illuminate\Support\Js::from(route('pos.customers.search', [], false)) }}, window.location.origin);
                    url.searchParams.set('q', code);
                    const resp = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (resp.ok) {
                        const customers = await resp.json();
                        const exact = customers.find(c => String(c.public_code || '').toUpperCase() === code.toUpperCase());
                        if (exact) {
                            this.selectCustomer(exact);
                            return;
                        }
                        if (customers.length === 1) {
                            this.selectCustomer(customers[0]);
                            return;
                        }
                    }
                } catch (e) {}
            }
            this.query = code;
            this.searchProducts();
        },
        openOrderRequest() {
            this.orderRequest = { open: true, saving: false, query: '', results: [], loading: false, requestNumber: 0, items: [], notes: '', error: '', result: null };
            this.$nextTick(() => this.$refs.orderProductSearch?.focus());
        },
        closeOrderRequest() {
            if (this.orderRequest.saving) return;
            this.orderRequest.open = false;
            this.$nextTick(() => this.focusSearch());
        },
        async searchOrderProducts() {
            const term = this.orderRequest.query.trim();
            const currentRequest = ++this.orderRequest.requestNumber;
            if (!term) { this.orderRequest.results = []; this.orderRequest.loading = false; return; }
            this.orderRequest.loading = true;
            this.orderRequest.error = '';
            try {
                const url = new URL({{ Illuminate\Support\Js::from(route('pos.products.search', [], false)) }}, window.location.origin);
                url.searchParams.set('q', term);
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                const products = await this.readFetchResponse(response);
                if (currentRequest === this.orderRequest.requestNumber) this.orderRequest.results = products;
            } catch (error) {
                if (currentRequest === this.orderRequest.requestNumber) { this.orderRequest.results = []; this.orderRequest.error = error.message; }
            } finally {
                if (currentRequest === this.orderRequest.requestNumber) this.orderRequest.loading = false;
            }
        },
        addOrderProduct(product) {
            const existing = this.orderRequest.items.find(item => item.id === product.id);
            if (existing) existing.requested_quantity = Number(existing.requested_quantity) + 1;
            else this.orderRequest.items.push({ id: product.id, name: product.name, internal_code: product.internal_code, available_stock: Number(product.available_stock), sale_price: Number(product.sale_price), unit: product.unit, allows_decimals: !!product.allows_decimals, requested_quantity: 1, request_note: '' });
            this.orderRequest.query = '';
            this.orderRequest.results = [];
            this.orderRequest.requestNumber += 1;
            this.orderRequest.error = '';
            this.$nextTick(() => this.$refs.orderProductSearch?.focus());
        },
        removeOrderProduct(index) { this.orderRequest.items.splice(index, 1); },
        async submitOrderRequest() {
            if (this.orderRequest.saving || !this.orderRequest.items.length) return;
            for (const item of this.orderRequest.items) {
                const quantity = Number(item.requested_quantity);
                if (!Number.isFinite(quantity) || quantity <= 0 || (!item.allows_decimals && !Number.isInteger(quantity))) {
                    this.orderRequest.error = `Revise la cantidad solicitada de ${item.name}.`;
                    return;
                }
            }
            this.orderRequest.saving = true;
            this.orderRequest.error = '';
            try {
                const response = await fetch({{ Illuminate\Support\Js::from(route('pedidos.store', [], false)) }}, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ notes: this.orderRequest.notes || null, items: this.orderRequest.items.map(item => ({ product_id: item.id, requested_quantity: item.requested_quantity, request_note: item.request_note || null })) }),
                });
                this.orderRequest.result = await this.readFetchResponse(response);
            } catch (error) { this.orderRequest.error = error.message || 'No fue posible crear el pedido.'; }
            finally { this.orderRequest.saving = false; }
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
            this.$nextTick(() => this.focusSearch());
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
        money2(value) { return new Intl.NumberFormat('es-CR', { style: 'currency', currency: 'CRC', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value) || 0); },
        formatPoints(value) { return new Intl.NumberFormat('es-CR', { maximumFractionDigits: 4 }).format(Number(value) || 0); },
        formatQuantity(value) { return new Intl.NumberFormat('es-CR', { maximumFractionDigits: 4 }).format(Number(value) || 0); },
        otherStockLabel(product) { return product.other_branch_stock.map(stock => `${stock.branch_name} ${this.formatQuantity(stock.available_stock)}`).join(', '); },
        showStockLimit(item) { this.notice = `Existencia máxima disponible: ${this.formatQuantity(item.available_stock)}`; },
        openImage(product) {
            if (!product.has_image || !product.image_url) return;
            this.imageModal = { open: true, url: product.image_url, name: product.name };
        },
        closeImage() { this.imageModal = { open: false, url: null, name: '' }; },
        handleGlobalEnter(event) {
            if (event.defaultPrevented || this.orderRequest.open || this.checkout.open || this.quickCustomer.open || this.cameraScannerOpen || this.resultsOpen || this.customerResultsOpen) return;
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
            this.$nextTick(() => this.focusSearch());
        },
        unsupportedPaymentMethod(method) { return method.type === 'loyalty_points'; },
        methodUnavailable(method) { return this.unsupportedPaymentMethod(method) || this.checkout.payments.some(payment => payment.payment_method_id === method.id) || this.pendingBalance <= 0 || (method.type === 'credit' && this.checkout.payments.length > 0); },
        methodInitial(method) { return (method.name || '?').trim().charAt(0).toUpperCase(); },
        selectPaymentMethod(method) {
            if (this.methodUnavailable(method) || this.checkout.processing) return;
            this.checkout.draft.methodId = method.id;
            if (method.type === 'credit') {
                if (!this.selectedCustomer) { this.checkout.error = 'Para vender a crédito debe seleccionar un cliente.'; return; }
                if (Number(this.selectedCustomer.credit_limit) <= 0) { this.checkout.error = 'Este cliente no tiene crédito autorizado.'; return; }
                if (Number(this.selectedCustomer.credit_days) <= 0) { this.checkout.error = 'El cliente no tiene un plazo de crédito configurado.'; return; }
                if (this.creditAvailable < this.pendingBalance) { this.checkout.error = 'El cliente no tiene crédito disponible suficiente.'; return; }
            }
            const amount = Number(this.checkout.draft.amount);
            if (!/^\d+$/.test(String(this.checkout.draft.amount)) || amount <= 0 || amount > this.pendingBalance) { this.checkout.error = 'Indique un monto entero que no supere el saldo pendiente.'; this.$refs.checkoutAmount?.focus(); return; }
            this.checkout.error = '';
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
            this.checkout.payments.push({ payment_method_id: method.id, method_name: method.name, method_type: method.type, amount, received_amount: received, change_amount: method.allows_change ? received - amount : 0, reference: this.checkout.draft.reference.trim() || null });
            this.checkout.draft = { methodId: '', amount: String(this.pendingBalance), receivedAmount: String(this.pendingBalance), reference: '' };
            this.$nextTick(() => { this.$refs.checkoutAmount?.focus(); this.$refs.checkoutAmount?.select(); });
        },
        removePayment(index) { this.checkout.payments.splice(index, 1); this.checkout.error = ''; this.checkout.draft = { methodId: '', amount: String(this.pendingBalance), receivedAmount: String(this.pendingBalance), reference: '' }; },
        async confirmCheckout() {
            if (!this.checkoutCanConfirm) return;
            this.checkout.processing = true;
            this.checkout.error = '';
            try {
                const response = await fetch({{ Illuminate\Support\Js::from(route('pos.checkout', [], false)) }}, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({
                        checkout_token: this.checkoutToken,
                        cash_session_id: this.cashSessionId || null,
                        ...(this.canDiscount && this.numberValue(this._generalDiscountInput) > 0 ? {
                            discount_total: this.numberValue(this._generalDiscountInput),
                            discount_total_type: this._generalDiscountType,
                        } : {}),
                        ...(this.suspended.activeId ? { suspended_sale_id: this.suspended.activeId, recovery_token: this.suspended.recoveryToken } : {}),
                        ...(this.quoteId ? { quote_id: this.quoteId } : {}),
                        customer_id: this.customerId,
                        document_type: this.documentType,
                        ...(this.loyaltyRequestedPoints > 0 ? { requested_points: String(this.loyaltyRequestedPoints) } : {}),
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
                if (this.documentType === 'electronic_invoice') {
    this.documentType = 'electronic_ticket';
}
                this.clearSuspendedRecovery();
                this.quoteId = null;
                this.successMessage = payload.message;
            } catch (error) {
                this.checkout.error = error.message || 'No fue posible completar el cobro. Intente nuevamente.';
            } finally {
                this.checkout.processing = false;
            }
        },
        newSale() {
            this.checkoutToken = generateUUID();
            this.checkout = { open: false, processing: false, payments: [], draft: { methodId: '', amount: '', receivedAmount: '', reference: '' }, error: '', result: null };
            this._generalDiscountInput = '';
            this._generalDiscountType = 'fixed';
            this.successMessage = '';
            this.clearSuspendedRecovery();
            this.quoteId = null;
            this.refreshLoyalty();
            this.$nextTick(() => this.focusSearch());
        },
        clearSuspendedRecovery() { this.suspended.activeId = null; this.suspended.recoveryToken = null; this.suspended.warnings = []; this.suspended.customerInvalid = false; },
        async refreshLoyalty() {
            const currentRequest = ++this.loyaltyRequestNumber;
            if (!this.customerId || this.grandTotal <= 0) {
                this.loyalty = { loading: false, available: false, reason: '', balance_points: '0', point_value: '1', minimum_enabled: false, minimum_amount: '0', eligible: false, available_points: '0', available_money: '0', maximum_redemption_percent: '100', max_redeemable_money: '0', max_redeemable_points: '0', offers_allowed: true, requested: '' };
                return;
            }
            this.loyalty.loading = true;
            try {
                const url = new URL({{ Illuminate\Support\Js::from(route('pos.loyalty.summary', [], false)) }}, window.location.origin);
                url.searchParams.set('customer_id', String(this.customerId));
                url.searchParams.set('total', String(this.grandTotal));
                url.searchParams.set('has_offers', this.cart.some(item => item.is_offer) ? '1' : '0');
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                const payload = await this.readFetchResponse(response);
                if (currentRequest !== this.loyaltyRequestNumber) return;
                const requested = this.loyalty.requested;
                this.loyalty = Object.assign({}, payload, { loading: false, requested });
            } catch (error) {
                if (currentRequest === this.loyaltyRequestNumber) {
                    this.loyalty.available = false;
                    this.loyalty.reason = '';
                    this.loyalty.loading = false;
                }
            }
        },
        async createQuote() {
            if (!this.cart.length) { this.notice = 'Agregue al menos un producto antes de crear la cotización.'; return; }
            if (this.quoteId) { this.notice = 'Esta cotización ya está cargada como base editable.'; return; }
            if (this.creatingQuote) return;
            this.creatingQuote = true;
            this.notice = '';
            try {
                const response = await fetch({{ Illuminate\Support\Js::from(route('cotizaciones.store', [], false)) }}, {
                    method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ customer_id: this.customerId, ...(this.canDiscount && this.numberValue(this._generalDiscountInput) > 0 ? { discount_total: this.numberValue(this._generalDiscountInput), discount_total_type: this._generalDiscountType } : {}), items: this.cart.map(item => ({ product_id: item.id, quantity: item.quantity, ...(this.canDiscount && this.numberValue(item._discount) > 0 ? { discount: this.numberValue(item._discount), discount_type: item._discountType } : {}), ...(this.canOverridePrice && this.numberValue(item._unitPrice) > 0 ? { unit_price: this.numberValue(item._unitPrice) } : {}) })) }),
                });
                const payload = await this.readFetchResponse(response);
                window.location.assign(payload.show_url);
            } catch (error) { this.notice = error.message; this.creatingQuote = false; }
        },
        async loadQuote(id) {
            try {
                const response = await fetch(`/cotizaciones/${id}/cargar`, { headers: { Accept: 'application/json' } });
                const payload = await this.readFetchResponse(response);
                this.quoteId = payload.quote_id;
                this.cart = payload.items.map(item => ({ id: item.product_id, name: item.name, internal_code: item.code, barcode: item.barcode, quantity: Number(item.quantity), sale_price: Number(item.sale_price), wholesale_price: item.wholesale_price, price_a: item.price_a, price_b: item.price_b, price_c: item.price_c, tax_rate: Number(item.tax_rate), available_stock: Number(item.available_stock), controls_inventory: !!item.controls_inventory, allows_decimals: !!item.allows_decimals, unavailable: !!item.unavailable, _discount: this.canDiscount ? Number(item.discount_total) : 0, _discountType: 'fixed', _unitPrice: this.canOverridePrice ? String(item.unit_price) : '' }));
                this.customerId = payload.customer?.id || null; this.selectedCustomer = payload.customer; this.checkoutToken = generateUUID(); this.notice = `Cotización ${payload.quote_number} cargada como base editable. La cotización original no se modificará.`;
            } catch (error) { this.notice = error.message; }
        },
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
            this.cart = []; this.customerId = null; this.selectedCustomer = null; this.checkout.payments = []; this.notice = ''; this.checkoutToken = generateUUID(); this._generalDiscountInput = ''; this._generalDiscountType = 'fixed'; this.quoteId = null;
            this.$nextTick(() => this.focusSearch());
        },
        async suspendCurrent() {
            if (!this.cart.length || this.suspended.saving) return;
            this.suspended.saving = true; this.notice = '';
            try {
                const recoveredCart = this.suspended.activeId && this.suspended.recoveryToken;
                const url = recoveredCart ? `/pos/suspendidas/${this.suspended.activeId}/volver-a-suspender` : {{ Illuminate\Support\Js::from(route('pos.suspended.store', [], false)) }};
                const response = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ ...(recoveredCart ? { recovery_token: this.suspended.recoveryToken } : {}), customer_id: this.customerId, items: this.cart.map(item => ({ product_id: item.id, quantity: item.quantity })) }) });
                const payload = await this.readFetchResponse(response);
                this.cart = []; this.customerId = null; this.selectedCustomer = null; this.checkoutToken = generateUUID(); this.clearSuspendedRecovery(); this.successMessage = payload.message;
            } catch (error) { this.notice = error.message; } finally { this.suspended.saving = false; }
        },
        async openSuspended() {
            this.suspended.open = true; this.suspended.loading = true; this.suspended.error = '';
            try { const response = await fetch({{ Illuminate\Support\Js::from(route('pos.suspended.index', [], false)) }}, { headers: { Accept: 'application/json' } }); const payload = await this.readFetchResponse(response); this.suspended.list = payload; }
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
                this.customerId = payload.customer?.id || null; this.selectedCustomer = payload.customer; this.suspended.activeId = payload.suspended_sale_id; this.suspended.recoveryToken = payload.recovery_token; this.suspended.warnings = payload.warnings || []; this.suspended.customerInvalid = !!payload.customer_invalid; this.notice = this.suspended.warnings.join(' '); this.checkoutToken = generateUUID(); this.checkout.payments = []; this.suspended.open = false; this.$nextTick(() => this.focusSearch());
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
                const url = new URL({{ Illuminate\Support\Js::from(route('pos.customers.search', [], false)) }}, window.location.origin);
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
        dropdownPos(refEl) {
            if (!refEl) return { top: '-9999px', left: '0px', width: '0px' };
            const section = refEl.closest('section');
            if (!section) return { top: '-9999px', left: '0px', width: '0px' };
            const sectionRect = section.getBoundingClientRect();
            const r = refEl.getBoundingClientRect();
            return { top: (r.bottom - sectionRect.top + 8) + 'px', left: (r.left - sectionRect.left) + 'px', width: r.width + 'px' };
        },
        openQuickCustomer() {
            this.quickCustomer.open = true;
            this.quickCustomer.delivery = null;
            this.quickCustomer.errors = {};
            this.quickCustomer.message = '';
            this.$nextTick(() => this.$refs.quickCustomerName?.focus());
        },
        closeQuickCustomer() {
            if (this.quickCustomer.saving) return;
            this.quickCustomer.open = false;
            this.quickCustomer.delivery = null;
            this.quickCustomer.errors = {};
            this.quickCustomer.message = '';
        },
        resetQuickCustomer() {
            this.quickCustomer.form = { name: '', customer_type: 'individual', identification_type: '', identification: '', phone: '', mobile: '', email: '', create_portal_access: false };
            this.quickCustomer.errors = {};
            this.quickCustomer.message = '';
            this.quickCustomer.delivery = null;
        },
        copyPortalAccess() {
            const text = this.quickCustomer.delivery?.copy_text || '';
            if (!text) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    this.successMessage = 'Acceso copiado al portapapeles.';
                }).catch(() => {
                    window.prompt('Copia manualmente:', text);
                });
            } else {
                window.prompt('Copia manualmente:', text);
            }
        },
        confirmPortalDelivery() {
            this.successMessage = this.quickCustomer.delivery ? `Cliente creado. Acceso Portal: ${this.quickCustomer.delivery.username} (contraseña mostrada una sola vez)` : this.successMessage;
            this.quickCustomer.open = false;
            this.quickCustomer.delivery = null;
            this.quickCustomer.form = { name: '', customer_type: 'individual', identification_type: '', identification: '', phone: '', mobile: '', email: '', create_portal_access: false };
        },
        async storeQuickCustomer() {
            if (this.quickCustomer.saving || !this.quickCustomer.form.name.trim()) return;
            this.quickCustomer.saving = true;
            this.quickCustomer.errors = {};
            this.quickCustomer.message = '';
            try {
                const response = await fetch({{ Illuminate\Support\Js::from(route('pos.customers.quick-store', [], false)) }}, {
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
                if (payload.portal_access && payload.portal_access.created) {
                    this.quickCustomer.delivery = payload.portal_access;
                    this.successMessage = '';
                    return;
                }
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
    }));
});
</script>
@endpush
