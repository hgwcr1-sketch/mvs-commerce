@extends('layouts.app')

@section('title', 'Configuración')
@section('description', 'Configuración administrativa de la empresa activa.')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-800">Configuración</h1>
        <p class="mt-1 text-sm text-slate-500">Parámetros administrativos de la empresa activa.</p>
    </div>

    @if(session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    <x-card>
        <x-slot:header>
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Fidelización</h2>
                <p class="text-sm text-slate-500">Configure el porcentaje aplicado al monto elegible de cada compra.</p>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <input type="hidden" name="birthday_enabled" value="0">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="birthday_enabled" value="1" @checked(old('birthday_enabled', $loyaltySetting->birthday_enabled)) class="mt-1 h-5 w-5 rounded border-slate-300 text-amber-500 focus:ring-amber-400">
                    <span><span class="block font-semibold text-slate-800">Bono de cumpleaños</span><span class="block text-sm text-slate-500">Acreditar una vez al año cuando el cliente completa una compra el día de su cumpleaños.</span></span>
                </label>

                <div class="mt-4">
                    <label for="birthday_points" class="mb-1 block text-sm font-semibold text-slate-700">Puntos de cumpleaños</label>
                    <input id="birthday_points" name="birthday_points" type="number" min="0" step="0.0001" required value="{{ old('birthday_points', $loyaltySetting->birthday_points) }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                    @error('birthday_points')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <input type="hidden" name="returning_customer_enabled" value="0">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="returning_customer_enabled" value="1" @checked(old('returning_customer_enabled', $loyaltySetting->returning_customer_enabled)) class="mt-1 h-5 w-5 rounded border-slate-300 text-amber-500 focus:ring-amber-400">
                    <span><span class="block font-semibold text-slate-800">Bono por retorno</span><span class="block text-sm text-slate-500">Premiar al cliente que vuelve después del período configurado sin compras calificables.</span></span>
                </label>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="returning_customer_days" class="mb-1 block text-sm font-semibold text-slate-700">Días sin comprar</label>
                        <input id="returning_customer_days" name="returning_customer_days" type="number" min="0" max="3650" step="1" required value="{{ old('returning_customer_days', $loyaltySetting->returning_customer_days) }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                        @error('returning_customer_days')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="returning_customer_points" class="mb-1 block text-sm font-semibold text-slate-700">Puntos por retorno</label>
                        <input id="returning_customer_points" name="returning_customer_points" type="number" min="0" step="0.0001" required value="{{ old('returning_customer_points', $loyaltySetting->returning_customer_points) }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                        @error('returning_customer_points')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
        </x-slot:header>

        <form method="POST" action="{{ route('configuracion.update', 'fidelidad') }}" class="max-w-xl space-y-5">
            @csrf
            @method('PUT')

            <div>
                <label for="earning_percentage" class="mb-1 block text-sm font-semibold text-slate-700">Porcentaje de acumulación</label>
                <div class="relative">
                    <input
                        id="earning_percentage"
                        name="earning_percentage"
                        type="number"
                        min="0.0001"
                        max="100"
                        step="0.0001"
                        required
                        value="{{ old('earning_percentage', $loyaltySetting->earning_percentage) }}"
                        class="h-12 w-full rounded-lg border border-slate-300 bg-white px-3 pr-10 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center font-semibold text-slate-500">%</span>
                </div>
                @error('earning_percentage')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                <p class="mt-2 text-sm text-slate-500">Ejemplo: 5 representa un 5% y genera 50 puntos sobre un monto elegible de ₡1.000.</p>
            </div>

            <div class="rounded-lg border px-4 py-3 text-sm {{ $loyaltySetting->is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800' }}">
                Fidelización está {{ $loyaltySetting->is_active ? 'activa' : 'desactivada' }} para esta empresa.
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <input type="hidden" name="earn_on_offers" value="0">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="earn_on_offers" value="1" @checked(old('earn_on_offers', $loyaltySetting->earn_on_offers)) class="mt-1 h-5 w-5 rounded border-slate-300 text-amber-500 focus:ring-amber-400">
                    <span><span class="block font-semibold text-slate-800">Acumular puntos en productos con precio de oferta</span><span class="block text-sm text-slate-500">Al desactivarlo solo se excluye el importe neto de líneas que usaron “Precio Oferta”. Los descuentos manuales siguen siendo elegibles.</span></span>
                </label>
            </div>

            <div>
                <label for="point_value" class="mb-1 block text-sm font-semibold text-slate-700">Valor monetario de 1 punto</label>
                <div class="relative"><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center font-semibold text-slate-500">₡</span><input id="point_value" name="point_value" type="number" min="0.0001" step="0.0001" required value="{{ old('point_value', $loyaltySetting->point_value ?? '1.0000') }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white pl-8 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200"></div>
                @error('point_value')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                <p class="mt-2 text-sm text-slate-500">Define cuánto vale cada punto al utilizarlo como medio de pago o canje. No modifica la acumulación.</p>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <input type="hidden" name="redemption_minimum_enabled" value="0">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="redemption_minimum_enabled" value="1" @checked(old('redemption_minimum_enabled', $loyaltySetting->redemption_minimum_enabled ?? false)) class="mt-1 h-5 w-5 rounded border-slate-300 text-amber-500 focus:ring-amber-400">
                    <span><span class="block font-semibold text-slate-800">Exigir monto mínimo para utilizar puntos</span><span class="block text-sm text-slate-500">Desactivado permite iniciar un canje desde el primer punto disponible.</span></span>
                </label>
                <div class="mt-4"><label for="redemption_minimum_amount" class="mb-1 block text-sm font-semibold text-slate-700">Monto monetario mínimo</label><div class="relative"><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center font-semibold text-slate-500">₡</span><input id="redemption_minimum_amount" name="redemption_minimum_amount" type="number" min="0" step="0.0001" value="{{ old('redemption_minimum_amount', $loyaltySetting->redemption_minimum_amount ?? '0.0000') }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white pl-8 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200"></div>@error('redemption_minimum_amount')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
            </div>

            <div>
                <label for="maximum_redemption_percent" class="mb-1 block text-sm font-semibold text-slate-700">Porcentaje máximo de la compra que puede pagarse con puntos</label>
                <div class="relative"><input id="maximum_redemption_percent" name="maximum_redemption_percent" type="number" min="0.0001" max="100" step="0.0001" required value="{{ old('maximum_redemption_percent', $loyaltySetting->maximum_redemption_percent ?? '100.0000') }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white px-3 pr-10 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200"><span class="pointer-events-none absolute inset-y-0 right-3 flex items-center font-semibold text-slate-500">%</span></div>
                @error('maximum_redemption_percent')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                <p class="mt-2 text-sm text-slate-500">Define cuánto del total elegible puede cubrir el cliente con sus puntos.</p>
            </div>

            @can('configuracion.editar')
                <button type="submit" class="rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-300">Guardar porcentaje</button>
            @endcan
        </form>
    </x-card>

    <x-card>
        <x-slot:header>
            <div>
                <h2 class="text-lg font-semibold text-slate-800">WhatsApp</h2>
                <p class="text-sm text-slate-500">Configure los teléfonos internacionales de la empresa para futuras acciones comerciales.</p>
            </div>
        </x-slot:header>

        <form method="POST" action="{{ route('configuracion.whatsapp.update') }}" class="max-w-xl space-y-5">
            @csrf
            @method('PUT')

            <input type="hidden" name="whatsapp_enabled" value="0">
            <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <input type="checkbox" name="whatsapp_enabled" value="1" @checked(old('whatsapp_enabled', $company->whatsapp_enabled)) class="mt-1 h-5 w-5 rounded border-slate-300 text-amber-500 focus:ring-amber-400">
                <span><span class="block font-semibold text-slate-800">Habilitar WhatsApp</span><span class="block text-sm text-slate-500">Deja lista la empresa para las funciones comerciales de una etapa posterior.</span></span>
            </label>

            <div>
                <label for="default_phone_country_code" class="mb-1 block text-sm font-semibold text-slate-700">Código de país predeterminado para clientes</label>
                <input id="default_phone_country_code" name="default_phone_country_code" type="text" inputmode="tel" maxlength="5" placeholder="+506" value="{{ old('default_phone_country_code', $company->default_phone_country_code) }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                @error('default_phone_country_code')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700">Número WhatsApp de la empresa</label>
                <div class="grid grid-cols-[7rem_1fr] gap-2">
                    <input name="whatsapp_phone_country_code" type="text" inputmode="tel" maxlength="5" placeholder="+506" aria-label="Código de país de WhatsApp" value="{{ old('whatsapp_phone_country_code', $company->whatsapp_phone_country_code) }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                    <input name="whatsapp_phone" type="text" inputmode="tel" maxlength="30" placeholder="83526142" aria-label="Número WhatsApp de la empresa" value="{{ old('whatsapp_phone', $company->whatsapp_phone) }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                </div>
                @error('whatsapp_phone_country_code')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                @error('whatsapp_phone')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            @can('configuracion.editar')
                <button type="submit" class="rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-300">Guardar WhatsApp</button>
            @endcan
        </form>
    </x-card>

    @can('fidelidad.configuracion')
        <x-card>
            <x-slot:header><div><h2 class="text-lg font-semibold text-slate-800">Plantillas de Fidelización</h2><p class="text-sm text-slate-500">Variables permitidas: {nombre}, {dias_sin_comprar}, {puntos}, {sucursal}.</p></div></x-slot:header>
            <form method="POST" action="{{ route('configuracion.loyalty-templates.update') }}" class="max-w-2xl space-y-4">@csrf @method('PUT')
                @foreach(['birthday'=>'Cumpleaños','inactive_30'=>'+30 días','inactive_60'=>'+60 días','inactive_90'=>'+90 días'] as $type=>$label)
                    <div><label class="mb-1 block text-sm font-semibold text-slate-700">{{ $label }}</label><textarea name="templates[{{ $type }}]" rows="3" class="form-input" required>{{ old("templates.$type", $loyaltyMessageTemplates[$type]) }}</textarea>@error("templates.$type")<p class="text-sm text-red-600">{{ $message }}</p>@enderror</div>
                @endforeach
                <button class="rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black">Guardar plantillas</button>
            </form>
        </x-card>
    @endcan
</div>
@endsection
