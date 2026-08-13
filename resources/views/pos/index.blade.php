@extends('layouts.app')

@section('title', 'Punto de venta')

@section('content')
<div x-data="posTerminal" x-cloak class="space-y-5">
    <section class="rounded-2xl bg-slate-900 p-4 text-white shadow-lg">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div class="grid flex-1 grid-cols-2 gap-3 md:grid-cols-4">
                <div><p class="text-xs uppercase text-slate-400">Empresa</p><p class="font-semibold">{{ $company->trade_name }}</p></div>
                <div><p class="text-xs uppercase text-slate-400">Sucursal</p><p class="font-semibold">{{ $branch->name }}</p></div>
                <div><p class="text-xs uppercase text-slate-400">Cajero</p><p class="font-semibold">{{ $cashier->name }}</p></div>
                <div><p class="text-xs uppercase text-slate-400">Estado de caja</p><p class="font-semibold text-amber-400">Sin apertura</p></div>
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
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-500">Cliente</p>
                        <p class="mt-1 text-lg font-bold text-slate-800" x-text="selectedCustomer ? selectedCustomer.name : 'Consumidor Final'"></p>
                        <p x-show="selectedCustomer" class="text-sm text-slate-500">
                            Identificación: <span x-text="selectedCustomer?.identification || '—'"></span>
                        </p>
                    </div>
                    <button x-show="selectedCustomer" type="button" @click="clearCustomer"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                        Quitar cliente
                    </button>
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
                    <div class="flex justify-between"><span class="text-slate-300">Descuento</span><strong>₡0,00</strong></div>
                    <div class="flex justify-between"><span class="text-slate-300">Impuesto</span><strong x-text="money(taxTotal)"></strong></div>
                </div>
                <div class="my-5 border-t border-slate-700"></div>
                <div class="flex items-end justify-between"><span class="text-lg">Total</span><strong class="text-3xl text-amber-400" x-text="money(grandTotal)"></strong></div>
                <button type="button" disabled class="mt-5 w-full cursor-not-allowed rounded-2xl bg-slate-700 px-5 py-4 text-lg font-bold text-slate-400">Cobrar · Próximamente</button>
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
            return this.subtotal + this.taxTotal;
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
        money(value) { return new Intl.NumberFormat('es-CR', { style: 'currency', currency: 'CRC' }).format(Number(value) || 0); },
        formatQuantity(value) { return new Intl.NumberFormat('es-CR', { maximumFractionDigits: 4 }).format(Number(value) || 0); },
        otherStockLabel(product) { return product.other_branch_stock.map(stock => `${stock.branch_name} ${this.formatQuantity(stock.available_stock)}`).join(', '); },
        showStockLimit(item) { this.notice = `Existencia máxima disponible: ${this.formatQuantity(item.available_stock)}`; },
        openImage(product) {
            if (!product.has_image || !product.image_url) return;
            this.imageModal = { open: true, url: product.image_url, name: product.name };
        },
        closeImage() { this.imageModal = { open: false, url: null, name: '' }; },
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
    }));
});
</script>
@endpush
