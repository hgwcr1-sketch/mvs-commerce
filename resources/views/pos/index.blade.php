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
                <div><p class="text-xs uppercase text-slate-400">Estado de caja</p><p class="font-semibold text-amber-400">Sin apertura de caja</p></div>
            </div>
            <a href="{{ route('dashboard') }}"
               class="self-end rounded-xl border border-slate-600 px-5 py-2 font-medium hover:bg-slate-800 xl:self-auto">
                Volver
            </a>
        </div>
    </section>

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
                    <h2 class="text-lg font-bold text-slate-800">Carrito temporal</h2>
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
                            <template x-for="item in cart" :key="item.id">
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
                                            <button type="button" @click="increase(item)" class="h-9 w-9 rounded-lg bg-amber-500 text-lg font-bold text-white hover:bg-amber-600">+</button>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-right" x-text="money(item.sale_price)"></td>
                                    <td class="px-4 py-4 text-right">₡0,00</td>
                                    <td class="px-4 py-4 text-right" x-text="money(lineTax(item))"></td>
                                    <td class="px-4 py-4 text-right font-bold" x-text="money(lineTotal(item))"></td>
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
                                    class="rounded-lg bg-amber-500 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-600">
                                + Nuevo cliente
                            </button>
                        @endcan
                        <button x-show="selectedCustomer" type="button" @click="clearCustomer"
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
                    <div class="flex justify-between"><span class="text-slate-300">Descuento</span><strong>₡0</strong></div>
                    <div class="flex justify-between"><span class="text-slate-300">Impuesto</span><strong x-text="money(taxTotal)"></strong></div>
                </div>
                <div class="my-5 border-t border-slate-700"></div>
                <div class="flex items-end justify-between"><span class="text-lg">Total</span><strong class="text-3xl text-amber-400" x-text="money(grandTotal)"></strong></div>
                @can('ventas.crear')
                    <button type="button" @click="openCheckout" :disabled="!canCheckout"
                            class="mt-5 w-full rounded-2xl bg-amber-500 px-5 py-4 text-lg font-bold text-white disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-400">
                        Cobrar
                    </button>
                @endcan
            </section>
        </aside>
    </div>

    <section class="rounded-2xl bg-white p-3 shadow-sm">
        <div class="flex gap-2 overflow-x-auto pb-1">
            <button type="button" class="whitespace-nowrap rounded-xl bg-amber-500 px-4 py-3 font-bold text-white">Tiquete electrónico</button>
            @foreach(['Factura electrónica', 'Pedido', 'Apartado', 'Abono', 'Cotización', 'Nota de crédito', 'Nota de débito', 'Suspender'] as $option)
                <button type="button" disabled class="whitespace-nowrap rounded-xl border border-slate-200 bg-slate-100 px-4 py-3 text-sm font-medium text-slate-400" title="Próximamente">{{ $option }} · Próximamente</button>
            @endforeach
        </div>
    </section>

    <div x-show="checkout.open" x-cloak @click.self="closeCheckout" @keydown.enter.window="if (checkoutCanConfirm && !selectedPaymentMethod) confirmCheckout()"
         class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/75 p-4"
         role="dialog" aria-modal="true" aria-label="Cobro">
        <div class="max-h-[94vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
            <template x-if="!checkout.result">
                <div>
                    <div class="flex items-center justify-between">
                        <div><h2 class="text-2xl font-bold text-slate-900">Cobro</h2><p class="text-sm text-amber-700">Sin apertura de caja</p></div>
                        <button type="button" @click="closeCheckout" :disabled="checkout.processing" class="text-3xl text-slate-500">×</button>
                    </div>
                    <div class="mt-5 space-y-2 rounded-xl bg-slate-100 p-4">
                        <div class="flex justify-between"><span>Subtotal</span><strong x-text="money(subtotal)"></strong></div>
                        <div class="flex justify-between"><span>Impuesto</span><strong x-text="money(taxTotal)"></strong></div>
                        <div x-show="roundingTotal !== 0" class="flex justify-between"><span>Ajuste por redondeo</span><strong x-text="money(roundingTotal)"></strong></div>
                        <div class="flex justify-between border-t border-slate-300 pt-2 text-xl"><span>Total</span><strong x-text="money(grandTotal)"></strong></div>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-slate-900 p-4 text-white md:grid-cols-4">
                        <div><span class="text-xs text-slate-400">Total</span><strong class="block" x-text="money(grandTotal)"></strong></div>
                        <div><span class="text-xs text-slate-400">Aplicado</span><strong class="block" x-text="money(appliedTotal)"></strong></div>
                        <div><span class="text-xs text-slate-400">Saldo</span><strong class="block" x-text="money(pendingBalance)"></strong></div>
                        <div><span class="text-xs text-slate-400">Vuelto</span><strong class="block text-green-400" x-text="money(totalPaymentChange)"></strong></div>
                    </div>
                    <p x-show="checkout.payments.length > 1" class="mt-3 rounded-lg bg-blue-50 p-2 text-center font-semibold text-blue-700">Pago mixto</p>
                    <label class="mt-5 block text-sm font-semibold">Método</label>
                    <select x-model.number="checkout.draft.methodId" @change="resetPaymentDraft" :disabled="checkout.processing" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3">
                        <option value="">Seleccione…</option>
                        <template x-for="method in availablePaymentMethods" :key="method.id"><option :value="method.id" x-text="method.name"></option></template>
                    </select>
                    <label class="mt-4 block text-sm font-semibold">Monto aplicado</label>
                    <div class="mt-1 flex gap-2"><input x-model="checkout.draft.amount" inputmode="numeric" pattern="[0-9]*" :disabled="checkout.processing" class="min-w-0 flex-1 rounded-xl border-2 border-slate-300 px-4 py-3 text-xl font-bold"><button type="button" @click="completeBalance" :disabled="checkout.processing || pendingBalance <= 0" class="rounded-xl bg-slate-200 px-3 font-semibold">Completar saldo</button></div>
                    <template x-if="selectedPaymentMethod?.allows_change"><div><label class="mt-4 block text-sm font-semibold">Monto recibido</label><input x-ref="receivedAmount" x-model="checkout.draft.receivedAmount" inputmode="numeric" pattern="[0-9]*" :disabled="checkout.processing" class="mt-1 w-full rounded-xl border-2 border-slate-300 px-4 py-3 text-xl font-bold"></div></template>
                    <template x-if="selectedPaymentMethod?.requires_reference"><div><label class="mt-4 block text-sm font-semibold">Referencia *</label><input x-model="checkout.draft.reference" maxlength="150" :disabled="checkout.processing" class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3"></div></template>
                    <div x-show="selectedPaymentMethod?.allows_change" class="mt-3 flex flex-wrap gap-2">
                        <template x-for="amount in suggestedAmounts" :key="amount">
                            <button type="button" @click="checkout.draft.receivedAmount = amount" :disabled="checkout.processing" class="rounded-lg bg-slate-200 px-3 py-2 font-semibold" x-text="money(amount)"></button>
                        </template>
                    </div>
                    <button type="button" @click="addPayment" :disabled="!canAddPayment || checkout.processing" class="mt-4 w-full rounded-xl bg-blue-600 px-4 py-3 font-bold text-white disabled:opacity-50">Agregar pago</button>
                    <div class="mt-4 space-y-2"><template x-for="(payment, index) in checkout.payments" :key="payment.payment_method_id"><div class="flex items-center justify-between rounded-xl border border-slate-200 p-3"><div><strong x-text="payment.method_name"></strong><p class="text-sm" x-text="money(payment.amount)"></p><p x-show="payment.reference" class="text-xs text-slate-500" x-text="`Ref: ${payment.reference}`"></p><p x-show="payment.change_amount > 0" class="text-xs text-green-700" x-text="`Recibido ${money(payment.received_amount)} · Vuelto ${money(payment.change_amount)}`"></p></div><button type="button" @click="removePayment(index)" :disabled="checkout.processing" class="text-sm font-semibold text-red-600">Eliminar</button></div></template></div>
                    <p x-show="checkoutError" x-text="checkoutError" class="mt-4 rounded-lg bg-red-50 p-3 text-sm font-semibold text-red-700"></p>
                    <button type="button" @click="confirmCheckout" :disabled="!checkoutCanConfirm"
                            class="mt-5 w-full rounded-xl bg-amber-500 px-5 py-4 text-lg font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"
                            x-text="checkout.processing ? 'Procesando…' : 'Confirmar cobro'"></button>
                </div>
            </template>
            <template x-if="checkout.result">
                <div class="text-center">
                    <h2 class="text-2xl font-bold text-green-700">Venta completada</h2>
                    <p class="mt-2 text-xl font-bold" x-text="checkout.result.sale_number"></p>
                    <p class="mt-4">Total: <strong x-text="money(checkout.result.total)"></strong></p>
                    <p>Vuelto total: <strong x-text="money(checkout.result.total_change)"></strong></p>
                    <div class="mx-auto mt-4 max-w-sm space-y-1 text-left"><template x-for="payment in checkout.result.payments"><p><strong x-text="payment.method_name"></strong>: <span x-text="money(payment.amount)"></span><span x-show="payment.reference" x-text="` · Ref: ${payment.reference}`"></span></p></template></div>
                    <p x-show="checkout.result.duplicate" class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Esta venta ya había sido procesada.</p>
                    <div class="mt-6 flex justify-center gap-3">
                        <a :href="checkout.result.receipt_url" target="_blank" class="rounded-xl bg-slate-900 px-5 py-3 font-bold text-white">Imprimir comprobante</a>
                        <button type="button" @click="newSale" class="rounded-xl bg-amber-500 px-5 py-3 font-bold text-white">Nueva venta</button>
                    </div>
                </div>
            </template>
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
                            class="rounded-xl bg-amber-500 px-5 py-3 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
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
        selectedCustomer: null,
        customerQuery: '',
        customerResults: [],
        customerSelectedIndex: 0,
        customerLoading: false,
        customerRequestNumber: 0,
        successMessage: '',
        checkoutToken: crypto.randomUUID(),
        paymentMethods: @json($paymentMethods->values()),
        checkout: { open: false, processing: false, payments: [], draft: { methodId: '', amount: '', receivedAmount: '', reference: '' }, error: '', result: null },
        quickCustomer: {
            open: false,
            saving: false,
            errors: {},
            message: '',
            form: { name: '', customer_type: 'individual', identification_type: '', identification: '', phone: '', mobile: '', email: '' },
        },

        get resultsOpen() {
            return this.query.trim().length > 0 && (this.loading || this.results.length >= 0);
        },
        get subtotal() {
            return this.cart.reduce((sum, item) => sum + (item.sale_price * item.quantity), 0);
        },
        get taxTotal() {
            return this.cart.reduce((sum, item) => sum + this.lineTax(item), 0);
        },
        get grandTotal() {
            return Math.round(this.subtotal + this.taxTotal);
        },
        get roundingTotal() { return this.grandTotal - (this.subtotal + this.taxTotal); },
        get availablePaymentMethods() { return this.paymentMethods.filter(method => !['credit', 'loyalty_points'].includes(method.type) && !this.checkout.payments.some(payment => payment.payment_method_id === method.id)); },
        get canCheckout() { return this.cart.length > 0 && !this.cart.some(item => this.exceedsStock(item)) && this.availablePaymentMethods.length > 0; },
        get selectedPaymentMethod() { return this.paymentMethods.find(method => method.id === Number(this.checkout.draft.methodId)); },
        get appliedTotal() { return this.checkout.payments.reduce((sum, payment) => sum + Number(payment.amount), 0); },
        get pendingBalance() { return Math.max(0, this.grandTotal - this.appliedTotal); },
        get totalPaymentChange() { return this.checkout.payments.reduce((sum, payment) => sum + Number(payment.change_amount), 0); },
        get checkoutCanConfirm() { return !this.checkout.processing && this.checkout.payments.length > 0 && this.pendingBalance === 0; },
        get checkoutError() { return this.checkout.error; },
        get canAddPayment() {
            const method = this.selectedPaymentMethod, amount = Number(this.checkout.draft.amount);
            if (!method || !/^\d+$/.test(String(this.checkout.draft.amount)) || amount <= 0 || amount > this.pendingBalance) return false;
            if (method.requires_reference && !this.checkout.draft.reference.trim()) return false;
            if (!method.allows_change) return true;
            if (!/^\d+$/.test(String(this.checkout.draft.receivedAmount)) || Number(this.checkout.draft.receivedAmount) < amount) return false;
            return Number(this.checkout.draft.receivedAmount) === amount || amount === this.pendingBalance;
        },
        get suggestedAmounts() {
            const total = Number(this.checkout.draft.amount) || this.pendingBalance;
            return [...new Set([total, 1000, 2000, 5000, 10000, 20000, 50000].filter(value => value >= total))].slice(0, 4);
        },
        get customerResultsOpen() {
            return this.customerQuery.trim().length > 0;
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
            else this.cart.push({ ...product, quantity: 1 });
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
        lineTax(item) { return item.sale_price * item.quantity * (item.tax_rate / 100); },
        lineTotal(item) { return (item.sale_price * item.quantity) + this.lineTax(item); },
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
            if (!this.checkout.payments.length) this.checkout.draft = { methodId: this.availablePaymentMethods.length === 1 ? this.availablePaymentMethods[0].id : '', amount: String(this.grandTotal), receivedAmount: String(this.grandTotal), reference: '' };
        },
        closeCheckout() { if (!this.checkout.processing) this.checkout.open = false; },
        resetPaymentDraft() { this.checkout.draft.amount = String(this.pendingBalance); this.checkout.draft.receivedAmount = String(this.pendingBalance); this.checkout.draft.reference = ''; },
        completeBalance() { this.checkout.draft.amount = String(this.pendingBalance); if (this.selectedPaymentMethod?.allows_change) this.checkout.draft.receivedAmount = String(this.pendingBalance); },
        addPayment() {
            if (!this.canAddPayment) return;
            const method = this.selectedPaymentMethod, amount = Number(this.checkout.draft.amount), received = method.allows_change ? Number(this.checkout.draft.receivedAmount) : amount;
            this.checkout.payments.push({ payment_method_id: method.id, method_name: method.name, amount, received_amount: received, change_amount: method.allows_change ? received - amount : 0, reference: this.checkout.draft.reference.trim() || null });
            this.checkout.draft = { methodId: '', amount: String(this.pendingBalance), receivedAmount: String(this.pendingBalance), reference: '' };
        },
        removePayment(index) { this.checkout.payments.splice(index, 1); this.checkout.error = ''; },
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
                        customer_id: this.customerId,
                        payments: this.checkout.payments.map(({ payment_method_id, amount, received_amount, reference }) => ({ payment_method_id, amount, received_amount, reference })),
                        items: this.cart.map(item => ({ product_id: item.id, quantity: item.quantity })),
                    }),
                });
                const payload = await response.json();
                if (!response.ok) { this.checkout.error = payload.message || 'No fue posible completar el cobro.'; return; }
                this.checkout.result = payload;
                this.cart = [];
                this.customerId = null;
                this.selectedCustomer = null;
                this.successMessage = payload.message;
            } catch (error) {
                this.checkout.error = 'No fue posible completar el cobro. Intente nuevamente.';
            } finally {
                this.checkout.processing = false;
            }
        },
        newSale() {
            this.checkoutToken = crypto.randomUUID();
            this.checkout = { open: false, processing: false, payments: [], draft: { methodId: '', amount: '', receivedAmount: '', reference: '' }, error: '', result: null };
            this.successMessage = '';
            this.$nextTick(() => this.$refs.searchInput.focus());
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
            this.closeCustomerResults();
        },
        clearCustomer() {
            this.customerId = null;
            this.selectedCustomer = null;
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
                const payload = await response.json();
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
                this.quickCustomer.message = 'No fue posible crear el cliente. Intente nuevamente.';
            } finally {
                this.quickCustomer.saving = false;
            }
        },
    }));
});
</script>
@endpush
