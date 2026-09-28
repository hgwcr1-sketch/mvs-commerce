@csrf

@if($errors->any())
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-red-700">
        <p class="font-semibold">Revise la información ingresada:</p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <div>
        <label for="name" class="mb-2 block text-sm font-medium text-slate-700">Nombre <span class="text-red-500">*</span></label>
        <input id="name" name="name" type="text" maxlength="100" required
               value="{{ old('name', $paymentMethod->name) }}"
               class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0">
        <p class="mt-1 text-sm text-slate-500">Nombre que verá el cajero durante el cobro.</p>
    </div>

    <div>
        <label for="code" class="mb-2 block text-sm font-medium text-slate-700">Código <span class="text-red-500">*</span></label>
        @if($paymentMethod->exists && $paymentMethod->is_system)
            <input type="hidden" name="code" value="{{ $paymentMethod->code }}">
            <input id="code" type="text" value="{{ $paymentMethod->code }}" disabled
                   class="w-full rounded-xl border border-slate-200 bg-slate-100 px-4 py-3 text-slate-500">
        @else
            <input id="code" name="code" type="text" maxlength="50" required
                   value="{{ old('code', $paymentMethod->code) }}"
                   class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0">
        @endif
        <p class="mt-1 text-sm text-slate-500">Se guardará en minúsculas, usando números y guiones bajos.</p>
    </div>

    <div>
        <label for="type" class="mb-2 block text-sm font-medium text-slate-700">Tipo <span class="text-red-500">*</span></label>
        @if($paymentMethod->exists && $paymentMethod->is_system)
            <input type="hidden" name="type" value="{{ $paymentMethod->type }}">
            <select id="type" disabled class="w-full rounded-xl border border-slate-200 bg-slate-100 px-4 py-3 text-slate-500">
                <option>{{ $types[$paymentMethod->type] }}</option>
            </select>
        @else
            <select id="type" name="type" required
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0">
                @foreach($types as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $paymentMethod->type) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        @endif
        <p class="mt-1 text-sm text-slate-500">Clasificación operativa para el POS y futuras integraciones.</p>
    </div>

    <div>
        <label for="sort_order" class="mb-2 block text-sm font-medium text-slate-700">Orden <span class="text-red-500">*</span></label>
        <input id="sort_order" name="sort_order" type="number" min="0" required
               value="{{ old('sort_order', $paymentMethod->sort_order ?? 0) }}"
               class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0">
        <p class="mt-1 text-sm text-slate-500">Los números menores aparecen primero.</p>
    </div>

    <div class="space-y-4 rounded-xl border border-slate-200 p-4 lg:col-span-2">
        <label class="flex items-start gap-3">
            <input type="checkbox" name="is_active" value="1"
                   @checked(old('is_active', $paymentMethod->exists ? $paymentMethod->is_active : true))
                   class="mt-1 rounded border-slate-300 text-amber-500 focus:ring-amber-500">
            <span><span class="block font-medium text-slate-700">Activa</span><span class="text-sm text-slate-500">Disponible para futuros cobros en el POS.</span></span>
        </label>

        <label class="flex items-start gap-3">
            <input type="checkbox" name="affects_cash" value="1" @checked(old('affects_cash', $paymentMethod->affects_cash))
                   class="mt-1 rounded border-slate-300 text-amber-500 focus:ring-amber-500">
            <span><span class="block font-medium text-slate-700">Afecta caja</span><span class="text-sm text-slate-500">El pago representa entrada o salida de efectivo físico.</span></span>
        </label>

        <label class="flex items-start gap-3">
            <input type="checkbox" name="requires_reference" value="1" @checked(old('requires_reference', $paymentMethod->requires_reference))
                   class="mt-1 rounded border-slate-300 text-amber-500 focus:ring-amber-500">
            <span><span class="block font-medium text-slate-700">Requiere referencia</span><span class="text-sm text-slate-500">Solicita comprobante, autorización o número de transacción.</span></span>
        </label>

        <label class="flex items-start gap-3">
            <input type="checkbox" name="allows_change" value="1" @checked(old('allows_change', $paymentMethod->allows_change))
                   class="mt-1 rounded border-slate-300 text-amber-500 focus:ring-amber-500">
            <span><span class="block font-medium text-slate-700">Permite vuelto</span><span class="text-sm text-slate-500">Permite recibir un monto mayor y calcular vuelto.</span></span>
        </label>
    </div>
</div>

<div class="mt-8 flex justify-end gap-3">
    <a href="{{ route('settings.pos.payment-methods.index') }}"
       class="rounded-xl border border-slate-300 px-6 py-3 hover:bg-slate-100">Cancelar</a>
    <button type="submit" class="rounded-xl bg-amber-500 px-6 py-3 font-semibold text-white hover:bg-amber-600">
        Guardar
    </button>
</div>
