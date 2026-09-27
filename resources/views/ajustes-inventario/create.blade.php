@extends('layouts.app')

@section('title', 'Ajustes de Inventario')

@section('content')

<div class="mx-auto max-w-5xl" x-data="adjustmentForm()">

    <div class="mb-6 flex items-start justify-between gap-4">

        <div>
            <h1 class="text-2xl font-bold text-slate-800">
                Nuevo Ajuste de Inventario
            </h1>

            <p class="mt-1 text-sm text-slate-500">
                Puede agregar uno o varios productos. El movimiento se aplicará únicamente a la sucursal activa y se guardará todo o nada.
            </p>
        </div>

        <a
            href="{{ route('inventario.index') }}"
            class="shrink-0 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
            ← Volver
        </a>

    </div>

    @php
        $generalErrors = collect($errors->messages())
            ->filter(fn ($messages, $key) => ! str_starts_with((string) $key, 'rows.'));
    @endphp

    {{-- Cambio de sucursal SIN recarga: el formulario nunca se envía ni se reinicia. --}}
    <div
        x-cloak
        x-show="!activeBranchId"
        class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        role="alert">
        <p class="font-semibold">No hay una sucursal activa.</p>
        <p class="mt-1">Seleccione una sucursal para aplicar el ajuste. Todo lo que ya haya llenado en este formulario se conservará.</p>
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Sucursal activa</p>
            <p class="mt-0.5 truncate font-semibold text-slate-800" x-text="activeBranchName || 'Sin sucursal activa'">{{ $activeBranchName ?: 'Sin sucursal activa' }}</p>
        </div>

        <form method="POST" action="{{ route('branch.active.update') }}" onsubmit="return false" class="shrink-0">
            @csrf

            <label class="sr-only" for="ajuste-branch">Sucursal activa</label>

            <select
                id="ajuste-branch"
                name="branch_id"
                data-current-branch="{{ $activeBranchId ?: '' }}"
                onchange="typeof mvsOnBranchChange === 'function' ? mvsOnBranchChange(event) : this.form.submit()"
                class="h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/40 sm:w-auto">
                <option value="" disabled @selected(! $activeBranchId)>Seleccione sucursal</option>

                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($activeBranchId === (int) $branch->id)>
                        {{ $branch->name }}
                    </option>
                @endforeach
            </select>
        </form>
    </div>

    @if ($generalErrors->isNotEmpty())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold">Revise los siguientes datos:</p>
            <ul class="mt-1 list-disc space-y-1 pl-5">
                @foreach ($generalErrors->flatten() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('ajustes-inventario.store') }}" @submit="clearDraft()">

        @csrf

        <div class="space-y-4">

            <template x-for="(row, index) in rows" :key="row.key">

                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">

                    <div class="mb-4 flex items-center justify-between gap-3">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Producto <span x-text="index + 1"></span>
                        </span>

                        <button
                            type="button"
                            x-show="rows.length > 1"
                            @click="removeRow(index)"
                            class="inline-flex min-h-11 items-center gap-1 rounded-lg border border-slate-300 px-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                            Quitar fila
                        </button>
                    </div>

                    {{-- Producto --}}
                    <div class="relative">
                        <label class="mb-2 block text-sm font-semibold text-slate-700">
                            Buscar producto *
                        </label>

                        <input
                            type="text"
                            x-model="row.search"
                            @input.debounce.250ms="search(index)"
                            autocomplete="off"
                            placeholder="Nombre, código interno o código de barras..."
                            class="h-12 w-full rounded-xl border border-slate-300 px-4 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">

                        <input type="hidden" :name="'rows[' + index + '][product_id]'" :value="row.product_id">
                        <input type="hidden" :name="'rows[' + index + '][product_label]'" :value="row.product_label">
                        <input type="hidden" :name="'rows[' + index + '][allows_decimals]'" :value="row.allows_decimals ? 1 : 0">

                        <div
                            x-show="row.open"
                            x-cloak
                            @click.outside="row.open = false"
                            class="absolute left-0 right-0 z-50 mt-1 max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">

                            <template x-if="row.results.length === 0">
                                <div class="px-4 py-4 text-sm text-slate-500">No se encontraron productos.</div>
                            </template>

                            <template x-for="product in row.results" :key="product.id">
                                <button
                                    type="button"
                                    @click="select(index, product)"
                                    class="block w-full border-b border-slate-100 px-4 py-3 text-left hover:bg-amber-50">
                                    <span class="block font-semibold text-slate-800" x-text="product.name"></span>
                                    <span class="mt-1 block text-xs text-slate-500">
                                        <span x-text="product.internal_code || ''"></span>
                                        <span x-text="product.barcode ? ' · ' + product.barcode : ''"></span>
                                    </span>
                                </button>
                            </template>
                        </div>

                        <div
                            x-show="row.product_label"
                            x-cloak
                            class="mt-3 flex items-start justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase text-amber-600">Producto seleccionado</p>
                                <p class="mt-1 break-words font-semibold text-slate-800" x-text="row.product_label"></p>
                            </div>
                            <button
                                type="button"
                                @click="clearProduct(index)"
                                class="min-h-11 shrink-0 rounded-lg px-2 text-sm font-semibold text-amber-700 hover:bg-amber-100">
                                Cambiar
                            </button>
                        </div>

                        <p class="mt-1 text-sm text-red-600" x-show="fieldError(index, 'product_id')" x-text="fieldError(index, 'product_id')"></p>
                        <p class="mt-1 text-sm font-medium text-amber-700" x-show="fieldWarning(index, 'product_id')" x-text="fieldWarning(index, 'product_id')"></p>
                        <p class="mt-1 text-sm text-red-600" x-show="row.notice" x-text="row.notice"></p>
                    </div>

                    {{-- Tipo y cantidad --}}
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">

                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">
                                Tipo de ajuste *
                            </label>

                            <select
                                :name="'rows[' + index + '][adjustment_type]'"
                                x-model="row.adjustment_type"
                                @change="saveDraft()"
                                required
                                class="h-12 w-full rounded-xl border border-slate-300 px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                                <option value="">Seleccione...</option>
                                <option value="entry">Entrada (+)</option>
                                <option value="exit">Salida (-)</option>
                            </select>

                            <p class="mt-1 text-sm text-red-600" x-show="fieldError(index, 'adjustment_type')" x-text="fieldError(index, 'adjustment_type')"></p>
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">
                                Cantidad *
                            </label>

                            <input
                                type="number"
                                :name="'rows[' + index + '][quantity]'"
                                x-model="row.quantity"
                                @input="saveDraft()"
                                :min="row.allows_decimals ? '0.0001' : '1'"
                                :step="row.allows_decimals ? '0.0001' : '1'"
                                inputmode="decimal"
                                required
                                class="h-12 w-full rounded-xl border border-slate-300 px-3 text-right text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">

                            <p class="mt-1 text-sm text-red-600" x-show="fieldError(index, 'quantity')" x-text="fieldError(index, 'quantity')"></p>
                            <p class="mt-1 text-sm font-medium text-amber-700" x-show="fieldWarning(index, 'quantity')" x-text="fieldWarning(index, 'quantity')"></p>
                        </div>

                    </div>

                    {{-- Motivo --}}
                    <div class="mt-4">
                        <label class="mb-2 block text-sm font-semibold text-slate-700">
                            Motivo *
                        </label>

                        <input
                            type="text"
                            :name="'rows[' + index + '][reason]'"
                            x-model="row.reason"
                            @input="saveDraft()"
                            maxlength="255"
                            placeholder="Ej: Inventario inicial, corrección de conteo..."
                            required
                            class="h-12 w-full rounded-xl border border-slate-300 px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">

                        <p class="mt-1 text-sm text-red-600" x-show="fieldError(index, 'reason')" x-text="fieldError(index, 'reason')"></p>
                    </div>

                    {{-- Observación --}}
                    <div class="mt-4">
                        <label class="mb-2 block text-sm font-semibold text-slate-700">
                            Observación
                        </label>

                        <textarea
                            :name="'rows[' + index + '][notes]'"
                            x-model="row.notes"
                            @input="saveDraft()"
                            rows="2"
                            class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200"></textarea>
                    </div>

                </div>

            </template>

        </div>

        <button
            type="button"
            @click="addRow()"
            class="mt-4 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl border border-primary bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-amber-50 sm:w-auto">
            <span aria-hidden="true" class="text-base leading-none">+</span>
            Agregar producto
        </button>

        <div class="mt-8 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">

            <a
                href="{{ route('inventario.index') }}"
                class="rounded-xl border border-slate-300 px-6 py-3 text-center font-semibold text-slate-700 hover:bg-slate-50">
                Cancelar
            </a>

            <button
                type="submit"
                class="rounded-xl bg-primary px-6 py-3 font-semibold text-black hover:bg-primary-hover">
                Aplicar Ajuste
            </button>

        </div>

    </form>

</div>

<script>
    window.MVS_ADJUSTMENT_INITIAL = @js($initialRows);
    window.MVS_ADJUSTMENT_ERRORS = @js($errors->messages());
    window.MVS_ADJUSTMENT_BRANCH = @js(['id' => $activeBranchId, 'name' => $activeBranchName]);
    window.MVS_ADJUSTMENT_DRAFT_KEY = 'mvs-ajuste-inventario-borrador';

    function adjustmentForm() {
        return {
            rows: [],
            errors: window.MVS_ADJUSTMENT_ERRORS || {},
            warnings: {},
            activeBranchId: (window.MVS_ADJUSTMENT_BRANCH || {}).id || 0,
            activeBranchName: (window.MVS_ADJUSTMENT_BRANCH || {}).name || '',
            seq: 0,

            init() {
                const initial = Array.isArray(window.MVS_ADJUSTMENT_INITIAL)
                    ? window.MVS_ADJUSTMENT_INITIAL
                    : [];
                const draft = this.readDraft();
                const source = this.isBlank(initial) && draft.length > 0 ? draft : initial;

                this.rows = source.map((row) => this.makeRow(row));

                if (this.rows.length === 0) {
                    this.rows.push(this.makeRow({}));
                }

                // Borrador recuperado tras un cambio de sucursal: revalidar
                // productos y stock contra la sucursal activa.
                if (source === draft) {
                    this.revalidate();
                }

                document.addEventListener('mvs-branch-changed', (event) => {
                    const detail = event.detail || {};

                    if (detail.branch_id) {
                        this.activeBranchId = detail.branch_id;

                        const select = document.getElementById('ajuste-branch');

                        if (select && select.selectedIndex > -1) {
                            this.activeBranchName = select.options[select.selectedIndex].text.trim();
                        }
                    }

                    // Nunca se envía ni se reinicia el formulario: solo se
                    // marcan las filas que no aplican en la nueva sucursal.
                    this.revalidate();
                });
            },

            makeRow(data) {
                const row = data || {};
                const label = row.product_label || '';

                return {
                    key: ++this.seq,
                    product_id: row.product_id || '',
                    product_label: label,
                    allows_decimals: String(row.allows_decimals ?? '') === '1',
                    adjustment_type: row.adjustment_type || '',
                    quantity: row.quantity ?? '',
                    reason: row.reason || '',
                    notes: row.notes || '',
                    search: label,
                    results: [],
                    open: false,
                    notice: '',
                };
            },

            addRow() {
                this.rows.push(this.makeRow({}));
                this.saveDraft();
            },

            removeRow(index) {
                if (this.rows.length <= 1) {
                    return;
                }

                this.rows.splice(index, 1);
                this.saveDraft();
            },

            clearProduct(index) {
                const row = this.rows[index];

                row.product_id = '';
                row.product_label = '';
                row.allows_decimals = false;
                row.search = '';
                row.results = [];
                row.open = false;
                row.notice = '';
                this.saveDraft();
            },

            fieldError(index, field) {
                const messages = this.errors['rows.' + index + '.' + field];

                return (messages && messages[0]) || '';
            },

            fieldWarning(index, field) {
                const messages = this.warnings['rows.' + index + '.' + field];

                return (messages && messages[0]) || '';
            },

            isSelected(productId, index) {
                return this.rows.some((row, rowIndex) =>
                    rowIndex !== index && String(row.product_id) === String(productId)
                );
            },

            select(index, product) {
                const row = this.rows[index];

                if (this.isSelected(product.id, index)) {
                    row.notice = 'Este producto ya está agregado en otra fila.';
                    row.open = false;
                    return;
                }

                const label = (product.internal_code ? product.internal_code + ' - ' : '') + product.name;

                row.product_id = product.id;
                row.product_label = label;
                row.allows_decimals = !!product.allows_decimals;
                row.search = label;
                row.results = [];
                row.open = false;
                row.notice = '';
                this.saveDraft();
            },

            async search(index) {
                const row = this.rows[index];
                const term = (row.search || '').trim();

                row.notice = '';

                if (!row.product_label || row.search !== row.product_label) {
                    row.product_id = '';
                    row.product_label = '';
                    row.allows_decimals = false;
                    this.saveDraft();
                }

                if (term.length < 1) {
                    row.results = [];
                    row.open = false;
                    return;
                }

                try {
                    const response = await fetch(
                        '{{ route('productos.search') }}?q=' + encodeURIComponent(term),
                        { headers: { 'Accept': 'application/json' } }
                    );

                    if (!response.ok) {
                        throw new Error('search failed');
                    }

                    row.results = await response.json();
                    row.open = true;
                } catch (error) {
                    row.results = [];
                    row.open = true;
                }
            },

            isBlank(rows) {
                if (!Array.isArray(rows) || rows.length === 0) {
                    return true;
                }

                return rows.every((row) => {
                    const item = row || {};

                    return !item.product_id
                        && !item.adjustment_type
                        && !String(item.quantity ?? '').trim()
                        && !String(item.reason ?? '').trim()
                        && !String(item.notes ?? '').trim();
                });
            },

            readDraft() {
                try {
                    const raw = sessionStorage.getItem(window.MVS_ADJUSTMENT_DRAFT_KEY);
                    const parsed = raw ? JSON.parse(raw) : [];

                    return Array.isArray(parsed) ? parsed : [];
                } catch (error) {
                    return [];
                }
            },

            saveDraft() {
                try {
                    const rows = this.rows
                        .map((row) => ({
                            product_id: row.product_id || '',
                            product_label: row.product_label || '',
                            allows_decimals: row.allows_decimals ? '1' : '0',
                            adjustment_type: row.adjustment_type || '',
                            quantity: row.quantity ?? '',
                            reason: row.reason || '',
                            notes: row.notes || '',
                        }))
                        .filter((row) => row.product_id
                            || row.adjustment_type
                            || String(row.quantity).trim()
                            || String(row.reason).trim()
                            || String(row.notes).trim());

                    if (rows.length > 0) {
                        sessionStorage.setItem(window.MVS_ADJUSTMENT_DRAFT_KEY, JSON.stringify(rows));
                    } else {
                        sessionStorage.removeItem(window.MVS_ADJUSTMENT_DRAFT_KEY);
                    }
                } catch (error) {
                    // Sin almacenamiento disponible el formulario sigue operativo.
                }
            },

            clearDraft() {
                try {
                    sessionStorage.removeItem(window.MVS_ADJUSTMENT_DRAFT_KEY);
                } catch (error) {
                    // Sin almacenamiento disponible el formulario sigue operativo.
                }
            },

            normalizeMap(map) {
                return Object.keys(map || {}).reduce((accumulator, key) => {
                    const value = map[key];

                    accumulator[key] = Array.isArray(value) ? value : [value];

                    return accumulator;
                }, {});
            },

            stripRowErrors(errors) {
                return Object.keys(errors || {}).reduce((accumulator, key) => {
                    if (! key.startsWith('rows.')) {
                        accumulator[key] = errors[key];
                    }

                    return accumulator;
                }, {});
            },

            async revalidate() {
                const rows = this.rows.map((row) => ({
                    product_id: row.product_id ? row.product_id : null,
                    product_label: row.product_label || null,
                    allows_decimals: row.allows_decimals ? 1 : 0,
                    adjustment_type: row.adjustment_type || null,
                    quantity: row.quantity === '' || row.quantity === null || typeof row.quantity === 'undefined'
                        ? null
                        : row.quantity,
                    reason: row.reason || null,
                    notes: row.notes || null,
                }));

                if (! rows.some((row) => row.product_id)) {
                    this.errors = this.stripRowErrors(this.errors);
                    this.warnings = {};
                    return;
                }

                const csrf = document.querySelector('meta[name="csrf-token"]');

                try {
                    const response = await fetch('{{ route('ajustes-inventario.revalidate') }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf ? csrf.content : '',
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ rows }),
                    });

                    if (!response.ok) {
                        throw new Error('revalidate failed');
                    }

                    const data = await response.json();

                    if (data.branch_id) {
                        this.activeBranchId = data.branch_id;
                        this.activeBranchName = data.branch_name || this.activeBranchName;
                    }

                    this.errors = Object.assign(
                        this.stripRowErrors(this.errors),
                        this.normalizeMap(data.errors)
                    );
                    this.warnings = this.normalizeMap(data.warnings);
                } catch (error) {
                    // Revalidación best-effort: nunca borra lo ya llenado.
                }
            },
        };
    }
</script>

@endsection
