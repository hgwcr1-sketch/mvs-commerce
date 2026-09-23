@extends('cobros.layout')
@section('title', 'Asignación de cartera — MVS CobrOS')
@section('content')
<style>
.mvs-search-select { position: relative; display: block; }
.mvs-search-select__wrap { position: relative; }
.mvs-search-select__input {
    width: 100%;
    min-height: 2.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    background: #fff;
    padding: 0 2.75rem 0 0.75rem;
    font-size: 0.875rem;
    line-height: 1.25rem;
    color: #0f172a;
    outline: none;
}
.mvs-search-select__input:focus {
    border-color: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2);
}
.mvs-search-select__input.is-invalid { border-color: #f87171; }
.mvs-search-select__clear {
    position: absolute;
    right: 0.125rem;
    top: 50%;
    transform: translateY(-50%);
    display: none;
    align-items: center;
    justify-content: center;
    width: 2.75rem;
    height: 2.75rem;
    border: none;
    background: transparent;
    color: #64748b;
    border-radius: 9999px;
    cursor: pointer;
    font-size: 1.25rem;
    line-height: 1;
}
.mvs-search-select.is-filled .mvs-search-select__clear { display: inline-flex; }
.mvs-search-select__clear:hover { background: #f1f5f9; color: #0f172a; }
.mvs-search-select__dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 50;
    margin-top: 0.25rem;
    max-height: 16rem;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08);
}
.mvs-search-select__option {
    min-height: 2.75rem;
    padding: 0.5rem 0.75rem;
    display: flex;
    align-items: center;
    font-size: 0.875rem;
    cursor: pointer;
}
.mvs-search-select__option--active,
.mvs-search-select__option:hover:not(.mvs-search-select__option--empty) {
    background: #fffbeb;
}
.mvs-search-select__option--empty {
    color: #64748b;
    cursor: default;
}
</style>

<div class="space-y-6">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('cobros.owner.dashboard') }}" class="text-sm font-semibold text-amber-700">← Inicio / Cartera</a>
            <h1 class="mt-2 text-2xl font-bold sm:text-3xl">Asignación de cartera</h1>
            <p class="mt-1 text-sm text-slate-500">Distribuya clientes de su empresa entre cobradores CobrOS.</p>
        </div>
    </header>

    <form method="GET" action="{{ route('cobros.owner.assignments') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div>
                <label for="filter-collector" class="text-sm font-semibold">Cobrador</label>
                <div class="mvs-search-select mt-2" data-options-source="collectors" data-empty-label="Todos" data-placeholder="Buscar cobrador…">
                    <input type="hidden" name="collector_user_id" value="{{ $filters['collector_user_id'] ?? '' }}">
                    <div class="mvs-search-select__wrap">
                        <input id="filter-collector" type="text" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" class="mvs-search-select__input" placeholder="Buscar cobrador…" value="{{ optional(collect($collectors)->firstWhere('id', $filters['collector_user_id'] ?? null))->name ?? '' }}">
                        <button type="button" class="mvs-search-select__clear" aria-label="Limpiar cobrador">×</button>
                    </div>
                    <div class="mvs-search-select__dropdown" role="listbox" hidden></div>
                </div>
            </div>
            <label class="text-sm font-semibold">
                Asignación
                <select name="assignment_status" class="mt-2 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3">
                    <option value="">Todas</option>
                    <option value="assigned" @selected($filters['assignment_status'] === 'assigned')>Asignados</option>
                    <option value="unassigned" @selected($filters['assignment_status'] === 'unassigned')>Sin asignar</option>
                </select>
            </label>
            <div>
                <label for="filter-branch" class="text-sm font-semibold">Sucursal (con CxC abierta)</label>
                <div class="mvs-search-select mt-2" data-options-source="branches" data-empty-label="Todas" data-placeholder="Buscar sucursal…">
                    <input type="hidden" name="branch_id" value="{{ $filters['branch_id'] ?? '' }}">
                    <div class="mvs-search-select__wrap">
                        <input id="filter-branch" type="text" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" class="mvs-search-select__input" placeholder="Buscar sucursal…" value="{{ optional(collect($branches)->firstWhere('id', $filters['branch_id'] ?? null))->name ?? '' }}">
                        <button type="button" class="mvs-search-select__clear" aria-label="Limpiar sucursal">×</button>
                    </div>
                    <div class="mvs-search-select__dropdown" role="listbox" hidden></div>
                </div>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button class="min-h-11 rounded-xl bg-amber-500 px-5 font-bold text-white hover:bg-amber-600">Filtrar</button>
            <a href="{{ route('cobros.owner.assignments') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-200 px-5 font-semibold hover:bg-slate-50">Limpiar</a>
        </div>
    </form>

    <section class="space-y-4">
        @forelse($rows as $row)
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-assignment-row>
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0">
                        <h2 class="break-words font-bold">{{ $row['customer']->name }}</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            @if($row['customer']->identification)
                                {{ $row['customer']->identification }} ·
                            @endif
                            CxC abierta: <strong class="text-slate-800">₡{{ number_format((float) $row['balance'], 2) }}</strong>
                        </p>
                        <p class="mt-1 text-sm">
                            Cobrador actual:
                            @if($row['collector'])
                                <strong class="text-emerald-700">{{ $row['collector']->name }}</strong>
                            @else
                                <span class="font-semibold text-slate-500">Sin asignar</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex w-full flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end lg:w-auto">
                        @if($row['assignment'])
                            <form method="POST" action="{{ route('cobros.owner.assignments.store') }}" class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-end">
                                @csrf
                                <input type="hidden" name="customer_id" value="{{ $row['customer']->id }}">
                                <div class="min-w-0 flex-1 sm:min-w-[16rem]">
                                    <label for="reassign-{{ $row['customer']->id }}" class="text-xs font-semibold">Reasignar</label>
                                    <div class="mvs-search-select mt-1" data-options-source="collectors" data-placeholder="Buscar cobrador…" data-exclude-id="{{ $row['collector']->id ?? '' }}" data-required="true">
                                        <input type="hidden" name="collector_user_id" value="">
                                        <div class="mvs-search-select__wrap">
                                            <input id="reassign-{{ $row['customer']->id }}" type="text" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" class="mvs-search-select__input" placeholder="Buscar cobrador…">
                                            <button type="button" class="mvs-search-select__clear" aria-label="Limpiar reasignación">×</button>
                                        </div>
                                        <div class="mvs-search-select__dropdown" role="listbox" hidden></div>
                                    </div>
                                </div>
                                <button class="min-h-11 rounded-lg bg-amber-500 px-4 text-sm font-bold text-white hover:bg-amber-600">Reasignar</button>
                            </form>
                            <form method="POST" action="{{ route('cobros.owner.assignments.destroy', $row['customer']) }}">
                                @csrf
                                @method('DELETE')
                                <button class="min-h-11 w-full rounded-lg border border-red-300 px-4 text-sm font-semibold text-red-700 hover:bg-red-50 sm:w-auto" onclick="return confirm('¿Quitar este cliente de la cartera?')">Quitar</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('cobros.owner.assignments.store') }}" class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-end">
                                @csrf
                                <input type="hidden" name="customer_id" value="{{ $row['customer']->id }}">
                                <div class="min-w-0 flex-1 sm:min-w-[16rem]">
                                    <label for="assign-{{ $row['customer']->id }}" class="text-xs font-semibold">Asignar a</label>
                                    <div class="mvs-search-select mt-1" data-options-source="collectors" data-placeholder="Buscar cobrador…" data-required="true">
                                        <input type="hidden" name="collector_user_id" value="">
                                        <div class="mvs-search-select__wrap">
                                            <input id="assign-{{ $row['customer']->id }}" type="text" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" class="mvs-search-select__input" placeholder="Buscar cobrador…">
                                            <button type="button" class="mvs-search-select__clear" aria-label="Limpiar asignación">×</button>
                                        </div>
                                        <div class="mvs-search-select__dropdown" role="listbox" hidden></div>
                                    </div>
                                </div>
                                <button class="min-h-11 rounded-lg bg-amber-500 px-4 text-sm font-bold text-white hover:bg-amber-600">Asignar</button>
                            </form>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <p class="text-sm text-slate-500">No hay clientes para mostrar con los filtros actuales.</p>
        @endforelse
    </section>

    <div class="flex flex-wrap items-center gap-2">
        {{ $customers->links() }}
    </div>
</div>

<script>
    window.__cobrosCollectors = @json($collectors->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(), JSON_HEX_QUOT|JSON_HEX_APOS);
    window.__cobrosBranches = @json($branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(), JSON_HEX_QUOT|JSON_HEX_APOS);
</script>

<script>
(function () {
    const SOURCES = {
        collectors: window.__cobrosCollectors || [],
        branches: window.__cobrosBranches || []
    };

    function normalize(str) {
        return String(str || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function init(wrapper) {
        const sourceKey = wrapper.dataset.optionsSource;
        const emptyLabel = wrapper.dataset.emptyLabel || '';
        const placeholder = wrapper.dataset.placeholder || '';
        const excludeId = String(wrapper.dataset.excludeId || '');
        const required = wrapper.dataset.required === 'true';
        const maxResults = 8;
        const allOptions = (SOURCES[sourceKey] || []).filter(function (item) {
            return String(item.id) !== excludeId;
        }).map(function (item) {
            return { value: String(item.id), label: String(item.name) };
        });

        const hidden = wrapper.querySelector('input[type="hidden"]');
        const visible = wrapper.querySelector('input[type="text"]');
        const dropdown = wrapper.querySelector('[role="listbox"]');
        const clearBtn = wrapper.querySelector('.mvs-search-select__clear');
        if (!hidden || !visible || !dropdown) return;

        wrapper.id = wrapper.id || ('mvs-ss-' + Math.random().toString(36).slice(2, 9));
        dropdown.id = wrapper.id + '-listbox';
        visible.setAttribute('aria-controls', dropdown.id);
        visible.setAttribute('placeholder', placeholder);

        let filtered = [];
        let activeIndex = -1;
        let open = false;
        let debounceTimer = null;

        function syncFilled() {
            wrapper.classList.toggle('is-filled', Boolean(visible.value || hidden.value));
        }

        function close() {
            dropdown.hidden = true;
            visible.setAttribute('aria-expanded', 'false');
            visible.removeAttribute('aria-activedescendant');
            open = false;
            activeIndex = -1;
        }

        function renderOptions() {
            dropdown.innerHTML = '';
            dropdown.hidden = false;
            open = true;
            visible.setAttribute('aria-expanded', 'true');

            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'mvs-search-select__option mvs-search-select__option--empty';
                empty.setAttribute('role', 'presentation');
                empty.textContent = visible.value.trim() ? 'Sin coincidencias' : 'Escriba para buscar';
                dropdown.appendChild(empty);
                activeIndex = -1;
                visible.removeAttribute('aria-activedescendant');
                return;
            }

            filtered.forEach(function (opt, idx) {
                const el = document.createElement('div');
                el.className = 'mvs-search-select__option' + (idx === activeIndex ? ' mvs-search-select__option--active' : '');
                el.setAttribute('role', 'option');
                el.setAttribute('id', dropdown.id + '-opt-' + idx);
                el.setAttribute('aria-selected', idx === activeIndex ? 'true' : 'false');
                el.textContent = opt.label;
                el.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    selectOption(idx);
                });
                dropdown.appendChild(el);
            });

            if (activeIndex >= 0) {
                visible.setAttribute('aria-activedescendant', dropdown.id + '-opt-' + activeIndex);
            } else {
                visible.removeAttribute('aria-activedescendant');
            }
        }

        function filter(query) {
            const q = normalize(query);
            if (!q) {
                filtered = emptyLabel ? [{ value: '', label: emptyLabel }] : [];
            } else {
                filtered = allOptions.filter(function (opt) {
                    return normalize(opt.label).indexOf(q) !== -1;
                }).slice(0, maxResults);
            }
            activeIndex = filtered.length ? 0 : -1;
            renderOptions();
        }

        function selectOption(idx) {
            const opt = filtered[idx];
            if (!opt) return;
            hidden.value = opt.value;
            visible.value = opt.value === '' ? '' : opt.label;
            visible.classList.remove('is-invalid');
            syncFilled();
            close();
        }

        function resolveFromText() {
            if (hidden.value) {
                const selected = allOptions.find(function (o) { return o.value === hidden.value; });
                if (selected && normalize(visible.value) === normalize(selected.label)) return true;
            }
            const q = normalize(visible.value);
            if (!q) {
                hidden.value = '';
                return !required;
            }
            const matches = allOptions.filter(function (opt) {
                return normalize(opt.label).indexOf(q) !== -1;
            });
            const exact = matches.find(function (opt) { return normalize(opt.label) === q; });
            const pick = exact || (matches.length === 1 ? matches[0] : null);
            if (!pick) return false;
            hidden.value = pick.value;
            visible.value = pick.label;
            syncFilled();
            return true;
        }

        visible.addEventListener('input', function () {
            const selected = allOptions.find(function (o) { return o.value === hidden.value; });
            if (!selected || normalize(visible.value) !== normalize(selected.label)) hidden.value = '';
            visible.classList.remove('is-invalid');
            syncFilled();
            if (debounceTimer) clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () { filter(visible.value); }, 80);
        });

        visible.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!open) filter(visible.value);
                else {
                    activeIndex = Math.min(activeIndex + 1, filtered.length - 1);
                    renderOptions();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!open) filter(visible.value);
                else {
                    activeIndex = Math.max(activeIndex - 1, 0);
                    renderOptions();
                }
            } else if (e.key === 'Enter') {
                if (open && activeIndex >= 0 && filtered[activeIndex]) {
                    e.preventDefault();
                    selectOption(activeIndex);
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                close();
            }
        });

        clearBtn.addEventListener('click', function () {
            hidden.value = '';
            visible.value = '';
            visible.classList.remove('is-invalid');
            syncFilled();
            visible.focus();
            close();
        });

        const form = wrapper.closest('form');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (resolveFromText()) {
                    visible.classList.remove('is-invalid');
                    return;
                }
                e.preventDefault();
                visible.classList.add('is-invalid');
                filter(visible.value);
                visible.focus();
            });
        }

        if (hidden.value) {
            const found = allOptions.find(function (o) { return o.value === String(hidden.value); });
            if (found) visible.value = found.label;
        }
        syncFilled();
    }

    document.querySelectorAll('.mvs-search-select').forEach(init);

    document.addEventListener('click', function (e) {
        if (e.target.closest('.mvs-search-select')) return;
        document.querySelectorAll('.mvs-search-select [role="listbox"]').forEach(function (d) { d.hidden = true; });
        document.querySelectorAll('.mvs-search-select input[type="text"]').forEach(function (i) { i.setAttribute('aria-expanded', 'false'); });
    });
})();
</script>
@endsection
