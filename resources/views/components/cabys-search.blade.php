@props([
    'state' => null,
])

{{--
    Buscador de CABYS para productos (CABYS EN PRODUCTO).

    El código NUNCA se escribe a mano: sale de la búsqueda contra el catálogo
    local versionado (`productos.cabys.search`). Lo elegido viaja como
    `cabys_proposed_code` y el servidor decide si queda `confirmed` o
    `pending` contra la versión vigente.

    Cuando no hay catálogo activo la operación sigue siendo normal: el
    formulario se guarda sin CABYS y la UI lo informa, sin bloquear el alta.
--}}

@php
    $initialCode = $state['code'] ?? old('cabys_proposed_code', '');
    $initialDescription = $state['description'] ?? null;
@endphp

<div class="md:col-span-2">
    <div x-data="mvsCabysSearch(@js([
        'code' => $initialCode !== '' ? $initialCode : null,
        'description' => $initialDescription,
        'tax_rate_raw' => $state['official_raw'] ?? null,
    ]))" class="relative">

        <label for="cabys-search-input" class="mb-1 block text-sm font-medium text-slate-700">
            Código CABYS
        </label>

        <p class="mb-2 text-xs text-slate-500">
            Busque en el catálogo oficial vigente (13 dígitos o descripción). Sin catálogo activo el
            producto se guarda igual, sin CABYS.
        </p>

        <input
            id="cabys-search-input"
            type="search"
            x-ref="input"
            x-model="query"
            @input.debounce.300ms="search()"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="selectCurrent()"
            @keydown.escape="close()"
            autocomplete="off"
            placeholder="Código o descripción del artículo…"
            aria-label="Buscar código CABYS en el catálogo oficial"
            class="form-input w-full">

        <input type="hidden" name="cabys_proposed_code" :value="selected?.code ?? ''">

        <div
            x-show="open"
            x-cloak
            class="absolute z-50 mt-1 max-h-72 w-full overflow-y-auto overflow-x-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
            <template x-for="(entry, index) in results" :key="entry.code">
                <button
                    type="button"
                    @click="select(entry)"
                    @mouseenter="highlighted = index"
                    :class="highlighted === index ? 'bg-amber-50 ring-1 ring-inset ring-amber-300' : 'hover:bg-slate-50'"
                    class="block min-h-11 w-full border-b border-slate-100 px-3 py-2.5 text-left last:border-0">
                    <p class="text-xs font-semibold tracking-wide text-amber-700" x-text="entry.code"></p>
                    <p class="text-sm text-slate-800" x-text="entry.description"></p>
                    <p class="text-xs text-slate-500" x-show="entry.tax_rate_raw" x-text="entry.tax_rate_raw"></p>                </button>
            </template>

            <p x-show="loading" class="px-3 py-3 text-center text-xs text-slate-500">Buscando…</p>

            <p
                x-show="!loading && searched && results.length === 0"
                class="px-3 py-4 text-center text-sm text-slate-500">
                Sin coincidencias en el catálogo vigente.
            </p>

            <p
                x-show="!loading && noCatalog"
                class="px-3 py-4 text-center text-sm text-amber-700">
                No hay catálogo CABYS activo: el producto se guardará sin código.
            </p>
        </div>

        <template x-if="selected">
            <div class="mt-2 flex min-h-11 flex-col gap-1 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="truncate text-xs font-semibold text-slate-800">
                        <span x-text="selected?.code"></span>
                        <span x-show="selected?.description" x-text="' · ' + selected?.description"></span>
                    </p>
                    <p class="text-xs text-slate-600">
                        <span x-show="selected?.tax_rate_raw">Tarifa oficial: </span>
                        <span x-text="selected?.tax_rate_raw ?? 'sin tarifa oficial en la fuente'"></span>
                    </p>
                </div>
                <button
                    type="button"
                    @click="clear()"
                    class="mt-1 min-h-11 shrink-0 self-start rounded-lg px-3 text-xs font-semibold text-red-700 hover:bg-red-50 sm:mt-0 sm:self-auto"
                    aria-label="Quitar el código CABYS seleccionado">
                    Quitar
                </button>
            </div>
        </template>

        @if ($state !== null)
            <div class="mt-2">
                @if (($state['status'] ?? null) === 'confirmed')
                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">
                        Confirmado contra el catálogo {{ $state['catalog_version'] ?? '' }}
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">
                        Pendiente de confirmar contra el catálogo vigente
                    </span>
                @endif
            </div>
        @endif

        @error('cabys_proposed_code')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>
</div>

@once
    <script>
        (function () {
            if (window.mvsCabysSearch) { return; }

            window.mvsCabysSearch = function (initial) {
                return {
                    query: initial?.code ?? '',
                    results: [],
                    loading: false,
                    searched: false,
                    open: false,
                    noCatalog: false,
                    highlighted: 0,
                    requestId: 0,
                    selected: initial?.code
                        ? { code: initial.code, description: initial.description, tax_rate_raw: initial.tax_rate_raw }
                        : null,
                    search() {
                        const q = this.query.trim();
                        this.close();
                        this.selected = null;

                        if (q.length < 3) {
                            this.results = [];
                            this.searched = false;
                            this.noCatalog = false;
                            return;
                        }

                        this.loading = true;
                        this.searched = true;
                        this.open = true;
                        const id = ++this.requestId;

                        fetch("{{ route('productos.cabys.search') }}?q=" + encodeURIComponent(q), {
                            headers: { Accept: 'application/json' }
                        })
                            .then(r => r.json())
                            .then(data => {
                                if (id !== this.requestId) { return; }
                                this.noCatalog = data.error === 'no_catalog';
                                this.results = Array.isArray(data.entries) ? data.entries : [];
                                this.highlighted = 0;
                            })
                            .catch(() => {
                                this.results = [];
                                this.noCatalog = false;
                            })
                            .finally(() => { if (id === this.requestId) { this.loading = false; } });
                    },
                    move(dir) {
                        if (!this.results.length) { return; }
                        this.highlighted = (this.highlighted + dir + this.results.length) % this.results.length;
                    },
                    select(entry) {
                        this.selected = {
                            code: entry.code,
                            description: entry.description,
                            tax_rate_raw: entry.tax_rate_raw
                        };
                        this.query = entry.code;
                        this.open = false;
                    },
                    selectCurrent() {
                        if (this.results[this.highlighted]) { this.select(this.results[this.highlighted]); }
                    },
                    close() { this.open = false; },
                    clear() {
                        this.selected = null;
                        this.query = '';
                        this.results = [];
                        this.open = false;
                    }
                };
            };
        })();
    </script>
@endonce
