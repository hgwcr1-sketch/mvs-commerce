@csrf

<div class="grid grid-cols-1 gap-6 md:grid-cols-2">

    {{-- Nombre --}}
    <div>
        <label class="mb-2 block text-sm font-semibold text-slate-700">
            Nombre *
        </label>

        <input
            type="text"
            name="name"
            value="{{ old('name', $usuario->name ?? '') }}"
            class="w-full rounded-lg border border-slate-300 px-4 py-2 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

        @error('name')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    {{-- Correo --}}
    <div>
        <label class="mb-2 block text-sm font-semibold text-slate-700">
            Correo electrónico *
        </label>

        <input
            type="email"
            name="email"
            value="{{ old('email', $usuario->email ?? '') }}"
            class="w-full rounded-lg border border-slate-300 px-4 py-2 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

        @error('email')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    {{-- Teléfono --}}
    <div>
        <label class="mb-2 block text-sm font-semibold text-slate-700">
            Teléfono
        </label>

        <input
            type="text"
            name="phone"
            value="{{ old('phone', $usuario->phone ?? '') }}"
            class="w-full rounded-lg border border-slate-300 px-4 py-2 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

        @error('phone')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    {{-- Rol --}}
<div>
    <label class="mb-2 block text-sm font-semibold text-slate-700">
        Rol *
    </label>

    <select
        name="role_id"
        required
        class="w-full rounded-lg border border-slate-300 px-4 py-2 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

        <option value="">
            Seleccione un rol...
        </option>

        @foreach($roles as $role)

            <option
                value="{{ $role->id }}"
                @selected(old('role_id', $selectedRoleId ?? '') == $role->id)>

                {{ $role->name }}

            </option>

        @endforeach

    </select>

    @error('role_id')
        <p class="mt-1 text-sm text-red-600">
            {{ $message }}
        </p>
    @enderror
</div>

    {{-- Sucursales --}}
    <div class="md:col-span-2">

        <label class="mb-3 block text-sm font-semibold text-slate-700">
            Sucursales asignadas *
        </label>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">

            @forelse($branches as $branch)

                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-300 bg-white px-4 py-4 hover:border-amber-500">

                    <input
                        type="checkbox"
                        name="branches[]"
                        value="{{ $branch->id }}"
                        @checked(
                            in_array(
                                $branch->id,
                                old('branches', $selectedBranchIds ?? [])
                            )
                        )
                        class="h-5 w-5 rounded border-slate-300 text-amber-500 focus:ring-amber-500"
                    >

                    <div>
                        <div class="font-semibold text-slate-800">
                            {{ $branch->name }}
                        </div>

                        <div class="text-sm text-slate-500">
                            Código: {{ $branch->code }}
                        </div>
                    </div>

                </label>

            @empty

                <div class="md:col-span-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800">
                    No existen sucursales activas para esta empresa.
                </div>

            @endforelse

        </div>

        @error('branches')
            <p class="mt-2 text-sm text-red-600">
                {{ $message }}
            </p>
        @enderror

    </div>

    {{-- Estado --}}
    <div>
        <label class="mb-2 block text-sm font-semibold text-slate-700">
            Estado
        </label>

        <select
            name="is_active"
            class="w-full rounded-lg border border-slate-300 px-4 py-2 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

            <option value="1"
                @selected(old('is_active', $usuario->is_active ?? 1) == 1)>
                Activo
            </option>

            <option value="0"
                @selected(old('is_active', $usuario->is_active ?? 1) == 0)>
                Inactivo
            </option>

        </select>

    </div>

    {{-- Foto --}}
    <div class="md:col-span-2">

        <label class="mb-2 block text-sm font-semibold text-slate-700">
            Fotografía
        </label>

        <input
            type="file"
            name="photo"
            class="w-full rounded-lg border border-slate-300 px-4 py-2">

        @if(!empty($usuario?->photo))

            <img
                src="{{ asset('storage/'.$usuario->photo) }}"
                class="mt-4 h-24 w-24 rounded-full border object-cover">

        @endif

    </div>

    {{-- Contraseña --}}
<div>

    <label class="mb-2 block text-sm font-semibold text-slate-700">
        Contraseña
    </label>

    <div class="relative">

        <input
            id="password"
            type="password"
            name="password"
            class="w-full rounded-lg border border-slate-300 px-4 py-2 pr-12 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

        <button
            type="button"
            onclick="togglePassword('password', this)"
            class="absolute inset-y-0 right-0 flex items-center px-4 text-slate-500 hover:text-amber-600"
            aria-label="Mostrar contraseña">

            <span class="password-eye text-lg">👁</span>

        </button>

    </div>

    @error('password')
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror

</div>

{{-- Confirmación --}}
<div>

    <label class="mb-2 block text-sm font-semibold text-slate-700">
        Confirmar contraseña
    </label>

    <div class="relative">

        <input
            id="password_confirmation"
            type="password"
            name="password_confirmation"
            class="w-full rounded-lg border border-slate-300 px-4 py-2 pr-12 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

        <button
            type="button"
            onclick="togglePassword('password_confirmation', this)"
            class="absolute inset-y-0 right-0 flex items-center px-4 text-slate-500 hover:text-amber-600"
            aria-label="Mostrar contraseña">

            <span class="password-eye text-lg">👁</span>

        </button>

    </div>

</div>

<div class="mt-8 flex justify-end gap-3">

    <a href="{{ route('usuarios.index') }}">

        <x-button color="secondary">
            Cancelar
        </x-button>

    </a>

    <x-button
        color="primary"
        type="submit">

        Guardar Usuario

    </x-button>

    <script>
function togglePassword(inputId, button) {

    const input = document.getElementById(inputId);

    if (input.type === 'password') {
        input.type = 'text';
        button.setAttribute('aria-label', 'Ocultar contraseña');
    } else {
        input.type = 'password';
        button.setAttribute('aria-label', 'Mostrar contraseña');
    }
}
</script>

</div>