@csrf
@if($errors->any())<div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-red-700"><p class="font-semibold">Revise la información ingresada:</p><ul class="mt-2 list-disc space-y-1 pl-5 text-sm">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Una caja representa una terminal o gaveta física. Solamente puede existir una caja predeterminada por sucursal.</div>
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <div>
        <label for="branch_id" class="mb-2 block text-sm font-medium text-slate-700">Sucursal <span class="text-red-500">*</span></label>
        <select id="branch_id" name="branch_id" required @disabled($hasSessions) class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0 disabled:bg-slate-100 disabled:text-slate-500">
            <option value="">Seleccione una sucursal</option>
            @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int) old('branch_id', $cashRegister->branch_id) === $branch->id)>{{ $branch->name }}</option>@endforeach
        </select>
        @if($hasSessions)<input type="hidden" name="branch_id" value="{{ $cashRegister->branch_id }}"><p class="mt-1 text-sm text-slate-500">La sucursal no puede cambiarse porque la caja tiene sesiones históricas.</p>@endif
    </div>
    <div>
        <label for="code" class="mb-2 block text-sm font-medium text-slate-700">Código <span class="text-red-500">*</span></label>
        <input id="code" name="code" type="text" maxlength="50" required value="{{ old('code', $cashRegister->code) }}" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0">
        <p class="mt-1 text-sm text-slate-500">Se guardará en minúsculas, sin espacios y usando guiones bajos.</p>
    </div>
    <div class="lg:col-span-2">
        <label for="name" class="mb-2 block text-sm font-medium text-slate-700">Nombre <span class="text-red-500">*</span></label>
        <input id="name" name="name" type="text" maxlength="100" required value="{{ old('name', $cashRegister->name) }}" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0">
    </div>
    <div class="space-y-4 rounded-xl border border-slate-200 p-4 lg:col-span-2">
        <label class="flex items-start gap-3"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $cashRegister->exists ? $cashRegister->is_active : true)) class="mt-1 rounded border-slate-300 text-amber-500 focus:ring-amber-500"><span><span class="block font-medium text-slate-700">Activa</span><span class="text-sm text-slate-500">Disponible para operar cuando se implemente apertura de caja.</span></span></label>
        <label class="flex items-start gap-3"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $cashRegister->is_default)) class="mt-1 rounded border-slate-300 text-amber-500 focus:ring-amber-500"><span><span class="block font-medium text-slate-700">Predeterminada</span><span class="text-sm text-slate-500">Al seleccionarla se desmarca cualquier otra caja predeterminada de la misma sucursal.</span></span></label>
    </div>
</div>
<div class="mt-8 flex justify-end gap-3"><a href="{{ route('settings.cash-registers.index') }}" class="rounded-xl border border-slate-300 px-6 py-3 hover:bg-slate-100">Cancelar</a><button type="submit" class="rounded-xl bg-amber-500 px-6 py-3 font-semibold text-white hover:bg-amber-600">Guardar</button></div>
