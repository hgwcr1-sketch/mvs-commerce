@extends('layouts.app')

@section('title', 'Centro de reglas de Fidelización')
@section('description', 'Configuración centralizada de las reglas de bonos y promociones de Fidelización.')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-800">Centro de reglas</h1>
        <p class="mt-1 text-sm text-slate-500">Configure desde un solo lugar las reglas de acumulación, bonos, ofertas y vencimiento de Fidelización.</p>
    </div>

    @if(session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    @php
        $dec = fn ($v) => is_numeric($v) ? number_format((float) $v, 2, '.', '') : $v;
    @endphp

    <x-card>
        <x-slot:header>
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Reglas de la empresa activa</h2>
                <p class="text-sm text-slate-500">Estas reglas son la configuración real del módulo: los servicios de acumulación y canje las leen directamente.</p>
            </div>
        </x-slot:header>

        <form method="POST" action="{{ route('loyalty.rules.update') }}" class="max-w-xl space-y-5">
            @csrf
            @method('PUT')

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <input type="hidden" name="is_active" value="0">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $loyaltySetting->is_active)) class="mt-1 h-5 w-5 rounded border border-slate-300 accent-amber-500 focus:ring-amber-400">
                    <span><span class="block font-semibold text-slate-800">Fidelización activa</span><span class="block text-sm text-slate-500">Al desactivarla se detienen la acumulación de puntos, los bonos y los canjes para esta empresa.</span></span>
                </label>
                @error('is_active')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="earning_percentage" class="mb-1 block text-sm font-semibold text-slate-700">Porcentaje de acumulación</label>
                <div class="relative">
                    <input id="earning_percentage" name="earning_percentage" type="number" min="0.01" max="100" step="0.01" required value="{{ $dec(old('earning_percentage', $loyaltySetting->earning_percentage)) }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white px-3 pr-10 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center font-semibold text-slate-500">%</span>
                </div>
                @error('earning_percentage')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                <p class="mt-2 text-sm text-slate-500">Ejemplo: 5 genera 50 puntos sobre un monto elegible de ₡1.000.</p>
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

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <input type="hidden" name="earn_on_offers" value="0">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="earn_on_offers" value="1" @checked(old('earn_on_offers', $loyaltySetting->earn_on_offers)) class="mt-1 h-5 w-5 rounded border-slate-300 text-amber-500 focus:ring-amber-400">
                    <span><span class="block font-semibold text-slate-800">Acumular puntos en productos con precio de oferta</span><span class="block text-sm text-slate-500">Al desactivarlo solo se excluye el importe neto de líneas que usaron “Precio Oferta”.</span></span>
                </label>
            </div>

            <div>
                <label for="point_value" class="mb-1 block text-sm font-semibold text-slate-700">Valor monetario de 1 punto</label>
                <div class="relative"><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center font-semibold text-slate-500">₡</span><input id="point_value" name="point_value" type="number" min="0.01" step="0.01" required value="{{ $dec(old('point_value', $loyaltySetting->point_value ?? '1.0000')) }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white pl-8 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200"></div>
                @error('point_value')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <input type="hidden" name="redemption_minimum_enabled" value="0">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="redemption_minimum_enabled" value="1" @checked(old('redemption_minimum_enabled', $loyaltySetting->redemption_minimum_enabled ?? false)) class="mt-1 h-5 w-5 rounded border-slate-300 text-amber-500 focus:ring-amber-400">
                    <span><span class="block font-semibold text-slate-800">Exigir monto mínimo para utilizar puntos</span></span>
                </label>
                <div class="mt-4"><label for="redemption_minimum_amount" class="mb-1 block text-sm font-semibold text-slate-700">Monto monetario mínimo</label><div class="relative"><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center font-semibold text-slate-500">₡</span><input id="redemption_minimum_amount" name="redemption_minimum_amount" type="number" min="0" step="0.01" value="{{ $dec(old('redemption_minimum_amount', $loyaltySetting->redemption_minimum_amount ?? '0.0000')) }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white pl-8 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200"></div>@error('redemption_minimum_amount')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
            </div>

            <div>
                <label for="maximum_redemption_percent" class="mb-1 block text-sm font-semibold text-slate-700">Porcentaje máximo de la compra pagable con puntos</label>
                <div class="relative"><input id="maximum_redemption_percent" name="maximum_redemption_percent" type="number" min="0.01" max="100" step="0.01" required value="{{ $dec(old('maximum_redemption_percent', $loyaltySetting->maximum_redemption_percent ?? '100.0000')) }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white px-3 pr-10 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200"><span class="pointer-events-none absolute inset-y-0 right-3 flex items-center font-semibold text-slate-500">%</span></div>
                @error('maximum_redemption_percent')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <input type="hidden" name="expiration_enabled" value="0">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="expiration_enabled" value="1" @checked(old('expiration_enabled', $loyaltySetting->expiration_enabled)) class="mt-1 h-5 w-5 rounded border-slate-300 text-amber-500 focus:ring-amber-400">
                    <span><span class="block font-semibold text-slate-800">Los puntos vencen por inactividad</span><span class="block text-sm text-slate-500">Meses enteros libres de inactividad (1–120). El proceso automático diario aplica esta política.</span></span>
                </label>
                <div class="mt-4">
                    <label for="expiration_months" class="mb-1 block text-sm font-semibold text-slate-700">Meses de inactividad para el vencimiento</label>
                    <input id="expiration_months" name="expiration_months" type="number" min="1" max="120" step="1" value="{{ old('expiration_months', $loyaltySetting->expiration_months) }}" class="h-12 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                    @error('expiration_months')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <button type="submit" class="rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-300">Guardar reglas de Fidelización</button>
        </form>
    </x-card>

    <x-card>
        <x-slot:header>
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Incentivo de registro (P14)</h2>
                <p class="text-sm text-slate-500">Activa un incentivo base al registrarse; se concede una sola vez por cliente.</p>
            </div>
        </x-slot:header>
        <form method="POST" action="{{ route('loyalty.registration-incentive.update') }}" class="space-y-3">
            @csrf @method('PUT')
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <input type="hidden" name="is_enabled" value="0">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $registrationIncentive->is_enabled)) class="mt-1 h-5 w-5 rounded border border-slate-300 accent-amber-500">
                    <span><span class="block font-semibold text-slate-800">Incentivo de registro habilitado</span><span class="block text-sm text-slate-500">Cuando está activo, acredita 10 puntos mediante el Kardex de Fidelización. Los reintentos no duplican la concesión.</span></span>
                </label>
                @error('is_enabled')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="min-h-11 rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-300">Guardar incentivo</button>
        </form>
        <p class="mt-3 text-xs text-slate-500">P15 definirá el tipo y valor configurables del beneficio sin cambiar este control ni su auditoría.</p>
    </x-card>

    <x-card>
        <x-slot:header>
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Reglas complementarias</h2>
                <p class="text-sm text-slate-500">Accesos directos a las demás configuraciones de Fidelización, cada una con su permiso correspondiente.</p>
            </div>
        </x-slot:header>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @can('fidelidad.multiplicadores')
                <a href="{{ route('loyalty.multipliers.index') }}" class="rounded-xl border border-slate-200 p-4 transition hover:border-amber-400 hover:bg-amber-50"><span class="block font-semibold text-slate-800">Multiplicadores</span><span class="block text-sm text-slate-500">Factores temporales sobre la acumulación por sucursal o globales.</span></a>
            @endcan
            @can('fidelidad.premios')
                <a href="{{ route('loyalty.rewards.index') }}" class="rounded-xl border border-slate-200 p-4 transition hover:border-amber-400 hover:bg-amber-50"><span class="block font-semibold text-slate-800">Premios</span><span class="block text-sm text-slate-500">Catálogo de premios canjeables por puntos.</span></a>
            @endcan
            @can('fidelidad.canjes')
                <a href="{{ route('loyalty.redemptions.index') }}" class="rounded-xl border border-slate-200 p-4 transition hover:border-amber-400 hover:bg-amber-50"><span class="block font-semibold text-slate-800">Canjes de premios</span><span class="block text-sm text-slate-500">Registrar canjes directos y consultar el historial.</span></a>
            @endcan
            @can('fidelidad.ver')
                <a href="{{ route('loyalty.kardex.index') }}" class="rounded-xl border border-slate-200 p-4 transition hover:border-amber-400 hover:bg-amber-50"><span class="block font-semibold text-slate-800">Kardex</span><span class="block text-sm text-slate-500">Movimientos de puntos: acumulaciones, canjes, ajustes y vencimientos.</span></a>
            @endcan
            @can('configuracion.editar')
                <a href="{{ route('configuracion.index') }}" class="rounded-xl border border-slate-200 p-4 transition hover:border-amber-400 hover:bg-amber-50"><span class="block font-semibold text-slate-800">Configuración general</span><span class="block text-sm text-slate-500">Parámetros administrativos generales de la empresa.</span></a>
            @endcan
        </div>
    </x-card>
</div>
@endsection
