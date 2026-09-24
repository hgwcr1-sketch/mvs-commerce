@props([
    'name' => 'customer_id',
    'selectedCustomer' => null,
])

@php
    $preselected = $selectedCustomer ? [
        'id' => (int) $selectedCustomer->id,
        'name' => $selectedCustomer->name,
        'identification' => $selectedCustomer->identification,
    ] : null;
@endphp

<div
    x-data="mvsCustomerFilter(@js($preselected))"
    class="relative">
    <label for="customer-filter-input" class="mb-1 block text-sm font-semibold text-slate-700">
        Cliente
    </label>

    <input
        id="customer-filter-input"
        type="search"
        x-ref="input"
        x-model="query"
        @input.debounce.250ms="search()"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="selectCurrent()"
        @keydown.escape="close()"
        autocomplete="off"
        placeholder="Nombre, identificación, teléfono o correo…"
        class="min-h-10 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-amber-500 focus:ring-0">

    <input type="hidden" name="{{ $name }}" :value="selectedCustomer?.id ?? ''">

    <div
        x-show="open"
        x-cloak
        class="absolute z-50 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-2xl">
        <template x-for="(customer, index) in results" :key="customer.id">
            <button
                type="button"
                @click="select(customer)"
                @mouseenter="highlighted = index"
                :class="highlighted === index ? 'bg-amber-50 ring-1 ring-inset ring-amber-300' : 'hover:bg-slate-50'"
                class="block w-full border-b border-slate-100 px-3 py-2.5 text-left last:border-0">
                <p class="font-semibold text-slate-800" x-text="customer.name"></p>
                <p class="text-xs text-slate-500">
                    <span x-text="customer.identification || 'Sin identificación'"></span>
                    <span x-show="customer.phone || customer.mobile" x-text="' · ' + (customer.phone || customer.mobile)"></span>
                </p>
            </button>
        </template>
        <p x-show="!loading && query.trim().length > 0 && results.length === 0" class="px-3 py-4 text-center text-sm text-slate-500">
            No se encontraron clientes activos.
        </p>
        <p x-show="loading" class="px-3 py-2 text-center text-xs text-slate-500">Buscando…</p>
    </div>

    <template x-if="selectedCustomer">
        <div class="mt-1 flex min-h-10 items-center justify-between gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5">
            <p class="min-w-0 truncate text-xs font-semibold text-slate-800">
                <span x-text="selectedCustomer.name"></span>
                <span x-show="selectedCustomer.identification" x-text="' · ' + selectedCustomer.identification"></span>
            </p>
            <button
                type="button"
                @click="clear()"
                class="shrink-0 rounded-md px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50"
                aria-label="Quitar cliente">
                Quitar
            </button>
        </div>
    </template>
</div>

@once
    <script>
        (function () {
            if (window.mvsCustomerFilter) { return; }

            window.mvsCustomerFilter = function (selected) {
                return {
                    query: selected?.name ?? '',
                    results: [],
                    loading: false,
                    open: false,
                    highlighted: 0,
                    requestId: 0,
                    selectedCustomer: selected ?? null,
                    search() {
                        const q = this.query.trim();
                        if (!q) {
                            this.close();
                            this.selectedCustomer = null;
                            return;
                        }
                        this.loading = true;
                        this.open = true;
                        const id = ++this.requestId;
                        fetch("{{ route('clientes.search') }}?search=" + encodeURIComponent(q), {
                            headers: { Accept: 'application/json' }
                        })
                            .then(r => r.json())
                            .then(data => {
                                if (id !== this.requestId) { return; }
                                this.results = data;
                                this.highlighted = 0;
                            })
                            .catch(() => { this.results = []; })
                            .finally(() => { if (id === this.requestId) { this.loading = false; } });
                    },
                    move(dir) {
                        if (!this.results.length) { return; }
                        this.highlighted = (this.highlighted + dir + this.results.length) % this.results.length;
                    },
                    select(customer) {
                        this.selectedCustomer = customer;
                        this.query = customer.name;
                        this.open = false;
                    },
                    selectCurrent() {
                        if (this.results[this.highlighted]) { this.select(this.results[this.highlighted]); }
                    },
                    close() { this.open = false; },
                    clear() {
                        this.selectedCustomer = null;
                        this.query = '';
                        this.results = [];
                        this.open = false;
                    }
                };
            };
        })();
    </script>
@endonce