@extends('cobros.layout')

@section('title', 'Nuevo cliente — Mi cartera — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <a href="{{ route('cobros.collector.portfolio') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Volver a mi cartera</a>

    <div>
        <h1 class="text-2xl font-bold sm:text-3xl">Nuevo cliente</h1>
        <p class="mt-1 text-sm text-slate-500">El cliente se registra en {{ $company->trade_name }} y queda asignado automáticamente a su cartera.</p>
    </div>

    <form method="POST" action="{{ route('cobros.collector.customers.store') }}"
          class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        @csrf
        <input type="hidden" name="customer_type" value="individual">

        @if($errors->any())
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <p class="font-semibold">Por favor revise la información:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <label for="name" class="text-sm font-semibold">Nombre del cliente</label>
                <input id="name" name="name" type="text" maxlength="150" required
                       value="{{ old('name') }}"
                       class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Nombre completo o negocio">
                @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="identification" class="text-sm font-semibold">Identificación</label>
                <input id="identification" name="identification" type="text" maxlength="50"
                       value="{{ old('identification') }}"
                       class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Cédula o pasaporte (opcional)">
                @error('identification')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="phone" class="text-sm font-semibold">Teléfono</label>
                <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="15"
                       value="{{ old('phone') }}"
                       class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Ej. 88888888">
                @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="email" class="text-sm font-semibold">Correo electrónico</label>
                <input id="email" name="email" type="email" inputmode="email" maxlength="150"
                       value="{{ old('email') }}"
                       class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Opcional">
                @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="address" class="text-sm font-semibold">Dirección</label>
                <input id="address" name="address" type="text" maxlength="500"
                       value="{{ old('address') }}"
                       class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-base" placeholder="Provincia, cantón, distrito y señas">
                @error('address')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            @if($creditAssignmentEnabled)
                <div>
                    <label for="credit_limit" class="text-sm font-semibold">Monto / límite de crédito</label>
                    <div class="mt-2 flex min-h-[44px] items-center rounded-xl border border-slate-300 px-3">
                        <span class="mr-2 text-slate-500">₡</span>
                        <input id="credit_limit" name="credit_limit" type="text" inputmode="decimal" maxlength="16" required
                           value="{{ old('credit_limit', '0.00') }}"
                           class="min-h-[42px] w-full border-0 px-1 text-right text-base focus:ring-0">
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Máximo autorizado: ₡{{ $creditLimitMax }}</p>
                    @error('credit_limit')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="credit_days" class="text-sm font-semibold">Plazo de crédito (días, si asigna monto)</label>
                    <input id="credit_days" name="credit_days" type="number" inputmode="numeric" min="0" step="1"
                           value="{{ old('credit_days') }}"
                           class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-right text-base">
                    @error('credit_days')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-slate-500">El plazo se requiere para calcular el vencimiento de CxC.</p>
                </div>
            @endif
        </div>

        <div class="mt-4">
            <input type="hidden" name="latitude" value="" data-customer-latitude>
            <input type="hidden" name="longitude" value="" data-customer-longitude>
            <button type="button" data-customer-gps class="min-h-[48px] w-full rounded-xl border border-primary px-4 py-3 text-base font-semibold text-slate-800 hover:bg-primary/10">
                📍 GUARDAR UBICACIÓN ACTUAL
            </button>
            <p data-customer-gps-message class="mt-2 text-sm text-slate-600" role="status" aria-live="polite">
                La ubicación es opcional; también puede crear el cliente sin GPS.
            </p>
        </div>

        <div class="mt-4">
            <label for="notes" class="text-sm font-semibold">Notas</label>
            <textarea id="notes" name="notes" rows="3"
                      class="mt-2 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 py-2 text-base"
                      placeholder="Referencias, horarios, observaciones">{{ old('notes') }}</textarea>
            @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-500">
            @unless($creditAssignmentEnabled)
                La asignación de crédito está desactivada por el dueño. Si el cliente ya existe, avise a su supervisor para evitar duplicados.
            @else
                El nivel de precio se administra desde MVS Commerce. Si el cliente ya existe, avise a su supervisor para evitar duplicados.
            @endunless
        </p>

        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
            <a href="{{ route('cobros.collector.portfolio') }}"
               class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 px-6 text-sm font-semibold hover:bg-slate-50">Cancelar</a>
            <button type="submit" class="min-h-[44px] rounded-xl bg-primary px-6 text-sm font-bold text-white hover:bg-primary-hover">Crear y agregar a mi cartera</button>
        </div>
    </form>
</div>
<script>
    document.querySelector('[data-customer-gps]')?.addEventListener('click', () => {
        const message = document.querySelector('[data-customer-gps-message]');
        if (!navigator.geolocation) {
            message.textContent = 'GPS no disponible. Puede crear el cliente sin ubicación.';
            return;
        }
        message.textContent = 'Obteniendo ubicación…';
        navigator.geolocation.getCurrentPosition(
            (position) => {
                document.querySelector('[data-customer-latitude]').value = position.coords.latitude;
                document.querySelector('[data-customer-longitude]').value = position.coords.longitude;
                message.textContent = 'Ubicación agregada. Puede guardar el cliente.';
            },
            () => {
                message.textContent = 'No se pudo obtener GPS. Puede crear el cliente sin ubicación.';
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        );
    });
</script>
@endsection
