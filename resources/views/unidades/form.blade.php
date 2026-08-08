<div class="grid grid-cols-1 gap-6 md:grid-cols-2">

    <div>
        <label class="mb-2 block text-sm font-semibold text-slate-700">
            Nombre *
        </label>

        <input
            type="text"
            name="name"
            value="{{ old('name', $unit->name ?? '') }}"
            placeholder="Ejemplo: Unidad"
            required
            class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

        @error('name')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="mb-2 block text-sm font-semibold text-slate-700">
            Abreviatura *
        </label>

        <input
            type="text"
            name="abbreviation"
            value="{{ old('abbreviation', $unit->abbreviation ?? '') }}"
            placeholder="Ejemplo: UND"
            required
            class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

        @error('abbreviation')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="mb-2 block text-sm font-semibold text-slate-700">
            ¿Permite decimales?
        </label>

        <select name="allows_decimals"
                class="w-full rounded-xl border border-slate-300 px-4 py-3">

            <option value="0" @selected(old('allows_decimals', $unit->allows_decimals ?? 0) == 0)>
                No
            </option>

            <option value="1" @selected(old('allows_decimals', $unit->allows_decimals ?? 0) == 1)>
                Sí
            </option>

        </select>
    </div>

    <div>
        <label class="mb-2 block text-sm font-semibold text-slate-700">
            Estado
        </label>

        <select name="is_active"
                class="w-full rounded-xl border border-slate-300 px-4 py-3">

            <option value="1" @selected(old('is_active', $unit->is_active ?? 1) == 1)>
                Activa
            </option>

            <option value="0" @selected(old('is_active', $unit->is_active ?? 1) == 0)>
                Inactiva
            </option>

        </select>
    </div>

    <div>
        <label class="mb-2 block text-sm font-semibold text-slate-700">
            Orden
        </label>

        <input
            type="number"
            name="sort_order"
            min="0"
            value="{{ old('sort_order', $unit->sort_order ?? 0) }}"
            class="w-full rounded-xl border border-slate-300 px-4 py-3">
    </div>

</div>

<div class="mt-8 flex justify-end gap-3">

    <a href="{{ route('unidades.index') }}"
       class="rounded-xl bg-slate-100 px-6 py-3 font-semibold text-slate-700 hover:bg-slate-200">
        Cancelar
    </a>

    <button type="submit"
            class="rounded-xl bg-amber-500 px-6 py-3 font-semibold text-white hover:bg-amber-600">
        Guardar Unidad
    </button>

</div>