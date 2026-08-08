@csrf

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- Nombre -->
    <div class="lg:col-span-2">
        <label class="block text-sm font-medium text-slate-700 mb-2">
            Nombre <span class="text-red-500">*</span>
        </label>

        <input
            type="text"
            name="name"
            value="{{ old('name', $categoria->name ?? '') }}"
            class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0"
            required>
    </div>

    <!-- Categoría Padre -->
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-2">
            Categoría Padre
        </label>

        <select
            name="parent_id"
            class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500">

            <option value="">Ninguna</option>

            @foreach($categoriasPadre as $cat)
                <option
                    value="{{ $cat->id }}"
                    @selected(old('parent_id', $categoria->parent_id ?? '') == $cat->id)>
                    {{ $cat->name }}
                </option>
            @endforeach

        </select>
    </div>

    <!-- Orden -->
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-2">
            Orden
        </label>

        <input
            type="number"
            name="sort_order"
            value="{{ old('sort_order', $categoria->sort_order ?? 0) }}"
            class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500">
    </div>

    <!-- Estado -->
    <div class="lg:col-span-2">

        <label class="inline-flex items-center gap-3">

            <input
                type="checkbox"
                name="is_active"
                value="1"
                @checked(old('is_active', $categoria->is_active ?? true))
                class="rounded border-slate-300 text-amber-500 focus:ring-amber-500">

            <span class="text-slate-700">
                Categoría activa
            </span>

        </label>

    </div>

</div>

<div class="mt-8 flex justify-end gap-3">

    <a href="{{ route('categorias.index') }}"
       class="rounded-xl border border-slate-300 px-6 py-3 hover:bg-slate-100 transition">

        Cancelar

    </a>

    <button
        type="submit"
        class="rounded-xl bg-amber-500 px-6 py-3 font-semibold text-white hover:bg-amber-600 transition">

        Guardar Categoría

    </button>

</div>