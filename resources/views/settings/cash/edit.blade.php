@extends('layouts.app')

@section('title', 'Configuración de Caja')
@section('description', 'Define las reglas administrativas de operación, cierre y monedas para Caja.')

@section('content')
<div class="mx-auto max-w-6xl space-y-6"
     x-data="{
        acceptsUsd: {{ old('accepts_usd', $cashSetting->accepts_usd) ? 'true' : 'false' }},
        emails: @js(old('closure_email_recipients', $cashSetting->closure_email_recipients ?? [])),
        addEmail() { if (this.emails.length < 10) this.emails.push(''); },
        removeEmail(index) { this.emails.splice(index, 1); }
     }">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-slate-800">Configuración de Caja</h2>
            <p class="mt-1 text-sm text-slate-600">Configura la operación futura de cajas, cierres y monedas de la empresa activa.</p>
        </div>
        <a href="{{ route('configuracion.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 font-medium text-slate-700 hover:bg-slate-100">Volver</a>
    </div>

    @if(session('success'))<div class="rounded-lg border border-green-200 bg-green-50 p-4 text-green-700">{{ session('success') }}</div>@endif
    @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-700"><p class="font-semibold">Revise la información ingresada:</p><ul class="mt-2 list-disc space-y-1 pl-5 text-sm">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('settings.cash.update') }}" class="space-y-6">
        @csrf @method('PUT')

        <x-card>
            <x-slot:header><h3 class="text-lg font-semibold text-slate-800">Operación</h3></x-slot:header>
            <div class="space-y-5">
                <label class="flex items-start gap-3"><input type="checkbox" name="allow_multiple_registers" value="1" @checked(old('allow_multiple_registers', $cashSetting->allow_multiple_registers)) class="mt-1 rounded border-slate-300 text-amber-500 focus:ring-amber-500"><span><span class="block font-medium text-slate-700">Permitir múltiples cajas por sucursal</span><span class="text-sm text-slate-500">Permite mantener más de una terminal o gaveta activa en una misma sucursal.</span></span></label>

                <div><label for="session_mode" class="mb-2 block text-sm font-medium text-slate-700">Modo de sesión</label><select id="session_mode" name="session_mode" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0">
                    <option value="individual" @selected(old('session_mode', $cashSetting->session_mode) === 'individual')>Individual — solo quien abre opera la caja</option>
                    <option value="shared" @selected(old('session_mode', $cashSetting->session_mode) === 'shared')>Compartida — varios usuarios autorizados pueden operar</option>
                </select></div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4"><label class="flex items-start gap-3"><input type="hidden" name="require_open_session" value="0"><input type="checkbox" name="require_open_session" value="1" @checked(old('require_open_session', $cashSetting->require_open_session)) class="mt-1 rounded border-slate-300 text-amber-500"><span><span class="block font-medium text-slate-700">Exigir apertura antes de cobrar</span><span class="text-sm text-slate-500">Cuando está activo, el POS exige una sesión de caja abierta antes de completar una venta.</span></span></label>@error('require_open_session')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror</div>
            </div>
        </x-card>

        <x-card>
            <x-slot:header><h3 class="text-lg font-semibold text-slate-800">Cierre</h3></x-slot:header>
            <div class="grid gap-5 lg:grid-cols-2">
                <label class="flex items-start gap-3"><input type="checkbox" name="blind_closing" value="1" @checked(old('blind_closing', $cashSetting->blind_closing)) class="mt-1 rounded border-slate-300 text-amber-500 focus:ring-amber-500"><span><span class="block font-medium text-slate-700">Cierre ciego</span><span class="text-sm text-slate-500">El cajero no verá el efectivo esperado antes de confirmar el conteo.</span></span></label>
                <div><label for="difference_tolerance" class="mb-2 block text-sm font-medium text-slate-700">Tolerancia permitida (CRC)</label><input id="difference_tolerance" name="difference_tolerance" type="number" min="0" step="1" value="{{ old('difference_tolerance', number_format((float) $cashSetting->difference_tolerance, 0, '.', '')) }}" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0"></div>
                <label class="flex items-start gap-3"><input type="checkbox" name="require_difference_authorization" value="1" @checked(old('require_difference_authorization', $cashSetting->require_difference_authorization)) class="mt-1 rounded border-slate-300 text-amber-500 focus:ring-amber-500"><span><span class="block font-medium text-slate-700">Requerir autorización de diferencia</span><span class="text-sm text-slate-500">Solicita autorización cuando la diferencia supera la tolerancia.</span></span></label>
                <label class="flex items-start gap-3"><input type="checkbox" name="auto_print_closure" value="1" @checked(old('auto_print_closure', $cashSetting->auto_print_closure)) class="mt-1 rounded border-slate-300 text-amber-500 focus:ring-amber-500"><span><span class="block font-medium text-slate-700">Imprimir cierre automáticamente</span><span class="text-sm text-slate-500">Quedará preparado para el comprobante de cierre futuro.</span></span></label>
            </div>
        </x-card>

        <x-card>
            <x-slot:header><h3 class="text-lg font-semibold text-slate-800">Dólares opcionales</h3></x-slot:header>
            <label class="flex items-start gap-3"><input type="checkbox" name="accepts_usd" value="1" x-model="acceptsUsd" @checked(old('accepts_usd', $cashSetting->accepts_usd)) class="mt-1 rounded border-slate-300 text-amber-500 focus:ring-amber-500"><span><span class="block font-medium text-slate-700">Aceptar dólares</span><span class="text-sm text-slate-500">El cajero registrará el tipo de cambio vigente al abrir cada sesión.</span></span></label>
            <div x-cloak x-show="acceptsUsd" x-transition class="mt-5 grid gap-5 lg:grid-cols-3">
                <div><label for="usd_exchange_rate_min" class="mb-2 block text-sm font-medium text-slate-700">Tipo de cambio mínimo</label><input id="usd_exchange_rate_min" name="usd_exchange_rate_min" type="number" min="0.0001" step="0.0001" :disabled="!acceptsUsd" value="{{ old('usd_exchange_rate_min', $cashSetting->usd_exchange_rate_min) }}" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0"></div>
                <div><label for="usd_exchange_rate_max" class="mb-2 block text-sm font-medium text-slate-700">Tipo de cambio máximo</label><input id="usd_exchange_rate_max" name="usd_exchange_rate_max" type="number" min="0.0001" step="0.0001" :disabled="!acceptsUsd" value="{{ old('usd_exchange_rate_max', $cashSetting->usd_exchange_rate_max) }}" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0"></div>
                <div><label for="usd_change_policy" class="mb-2 block text-sm font-medium text-slate-700">Política de vuelto</label><select id="usd_change_policy" name="usd_change_policy" :disabled="!acceptsUsd" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0"><option value="crc_only" @selected(old('usd_change_policy', $cashSetting->usd_change_policy) === 'crc_only')>Vuelto solo en colones</option><option value="usd_only" @selected(old('usd_change_policy', $cashSetting->usd_change_policy) === 'usd_only')>Vuelto solo en dólares</option><option value="either" @selected(old('usd_change_policy', $cashSetting->usd_change_policy) === 'either')>Vuelto en cualquiera</option></select></div>
            </div>
            <p class="mt-4 text-sm text-slate-500">Las denominaciones USD se configurarán en una fase posterior.</p>
        </x-card>

        <x-card>
            <x-slot:header><div class="flex items-center justify-between"><h3 class="text-lg font-semibold text-slate-800">Correos para avisos de apertura y cierre</h3><button type="button" @click="addEmail" :disabled="emails.length >= 10" class="rounded-lg border border-amber-500 px-3 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50 disabled:opacity-40">+ Agregar correo</button></div></x-slot:header>
            <p class="mb-4 text-sm text-slate-500">Cada destinatario recibirá avisos independientes al abrir y cerrar definitivamente una sesión de Caja.</p>
            <div class="space-y-3"><template x-for="(email, index) in emails" :key="index"><div class="flex gap-3"><input type="email" name="closure_email_recipients[]" x-model="emails[index]" maxlength="150" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:ring-0" placeholder="correo@empresa.com"><button type="button" @click="removeEmail(index)" class="rounded-xl border border-red-200 px-4 text-sm font-semibold text-red-600 hover:bg-red-50">Quitar</button></div></template><p x-show="emails.length === 0" class="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">No hay destinatarios configurados.</p></div>
        </x-card>

        <x-card>
            <x-slot:header><h3 class="text-lg font-semibold text-slate-800">Resumen</h3></x-slot:header>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5"><div><dt class="text-xs uppercase text-slate-500">Sucursales</dt><dd class="mt-1 text-xl font-semibold">{{ $branchCount }}</dd></div><div><dt class="text-xs uppercase text-slate-500">Cajas activas</dt><dd class="mt-1 text-xl font-semibold">{{ $activeRegisterCount }}</dd></div><div><dt class="text-xs uppercase text-slate-500">Modo</dt><dd class="mt-1 font-semibold">{{ $cashSetting->session_mode === 'shared' ? 'Compartida' : 'Individual' }}</dd></div><div><dt class="text-xs uppercase text-slate-500">Cierre ciego</dt><dd class="mt-1 font-semibold">{{ $cashSetting->blind_closing ? 'Sí' : 'No' }}</dd></div><div><dt class="text-xs uppercase text-slate-500">Dólares</dt><dd class="mt-1 font-semibold">{{ $cashSetting->accepts_usd ? 'Sí' : 'No' }}</dd></div></dl>
        </x-card>

        <div class="flex justify-end gap-3"><a href="{{ route('configuracion.index') }}" class="rounded-xl border border-slate-300 px-6 py-3 hover:bg-slate-100">Volver</a><button type="submit" class="rounded-xl bg-amber-500 px-6 py-3 font-semibold text-white hover:bg-amber-600">Guardar configuración</button></div>
    </form>
</div>
@endsection
