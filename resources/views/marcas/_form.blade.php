@csrf

<div class="space-y-4">
    <div>
        <label>Nombre</label>
        <input type="text" name="name" value="{{ old('name',$marca->name ?? '') }}" class="border rounded px-3 py-2 w-full">
    </div>

    <div>
        <label>Descripción</label>
        <textarea name="description" class="border rounded px-3 py-2 w-full">{{ old('description',$marca->description ?? '') }}</textarea>
    </div>

    <label>
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active',$marca->is_active ?? true))>
        Activa
    </label>

    <button class="px-4 py-2 rounded bg-amber-500 text-white">
        {{ isset($marca) ? 'Actualizar' : 'Guardar' }}
    </button>
</div>
