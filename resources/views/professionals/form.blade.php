@csrf

<div class="space-y-6">
    <div>
        <label for="user_id" class="mb-2 block text-sm font-semibold text-slate-700">Usuario Core *</label>
        <select id="user_id" name="user_id" required class="min-h-12 w-full rounded-lg border border-slate-300 px-4 focus:border-amber-500 focus:ring-amber-500">
            <option value="">Seleccione un usuario de esta empresa</option>
            @foreach($users as $user)
                <option value="{{ $user->id }}" @selected((string) old('user_id', $professional->user_id ?? '') === (string) $user->id)>
                    {{ $user->name }} — {{ $user->email }}
                </option>
            @endforeach
        </select>
        <p class="mt-2 text-xs text-slate-500">El perfil profesional reutiliza la identidad existente; no crea otro usuario.</p>
        @error('user_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <fieldset>
        <legend class="mb-3 text-sm font-semibold text-slate-700">Sucursales donde atiende *</legend>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach($branches as $branch)
                <label class="flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border border-slate-300 p-4 focus-within:border-amber-500">
                    <input type="checkbox" name="branches[]" value="{{ $branch->id }}" class="h-5 w-5 rounded border-slate-300 text-amber-500" @checked(in_array($branch->id, old('branches', $selectedBranchIds)))>
                    <span class="font-medium text-slate-800">{{ $branch->name }}</span>
                </label>
            @endforeach
        </div>
        @error('branches')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
        @error('branches.*')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </fieldset>

    <fieldset>
        <legend class="mb-3 text-sm font-semibold text-slate-700">Especialidades</legend>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @forelse($specialties as $specialty)
                <label class="flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border border-slate-300 p-4 focus-within:border-amber-500">
                    <input type="checkbox" name="specialties[]" value="{{ $specialty->id }}" class="h-5 w-5 rounded border-slate-300 text-amber-500" @checked(in_array($specialty->id, old('specialties', $selectedSpecialtyIds)))>
                    <span>
                        <span class="block font-medium text-slate-800">{{ $specialty->name }}</span>
                        @if($specialty->description)<span class="block text-xs text-slate-500">{{ $specialty->description }}</span>@endif
                    </span>
                </label>
            @empty
                <p class="rounded-xl bg-slate-50 p-4 text-sm text-slate-500 sm:col-span-2">No hay especialidades activas configuradas.</p>
            @endforelse
        </div>
        @error('specialties.*')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </fieldset>

    <div>
        <label for="is_active" class="mb-2 block text-sm font-semibold text-slate-700">Estado</label>
        <select id="is_active" name="is_active" class="min-h-12 w-full rounded-lg border border-slate-300 px-4 sm:max-w-xs">
            <option value="1" @selected((string) old('is_active', isset($professional) ? (int) $professional->is_active : 1) === '1')>Activo</option>
            <option value="0" @selected((string) old('is_active', isset($professional) ? (int) $professional->is_active : 1) === '0')>Inactivo</option>
        </select>
    </div>

    <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end">
        <a href="{{ route('professionals.index') }}" class="inline-flex min-h-12 items-center justify-center rounded-lg border border-slate-300 px-5 font-semibold text-slate-700">Cancelar</a>
        <x-button type="submit" color="primary" class="min-h-12">Guardar profesional</x-button>
    </div>
</div>
