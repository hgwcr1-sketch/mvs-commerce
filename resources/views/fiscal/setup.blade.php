@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-2xl space-y-5 pb-24 md:pb-8">
    <section class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-7">
        <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Conectar facturación</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-950">
            @switch($step)
                @case('datos') Paso 1 de 5 · Datos fiscales @break
                @case('conexion') Paso 2 de 5 · Conexión fiscal @break
                @case('verificar') Paso 3 de 5 · Verificar conexión @break
                @case('preferencias') Paso 4 de 5 · Preferencias @break
                @default Paso 5 de 5 · Confirmación
            @endswitch
        </h1>

        @if(session('status'))
            <div class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">
                <ul class="list-disc pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($step === 'datos')
            <form method="POST" action="{{ route('fiscal.setup.store', ['step' => 'datos']) }}" class="mt-4 space-y-4">
                @csrf @method('PUT')
                <div>
                    <label class="text-sm font-bold" for="identification_type">Tipo de identificación</label>
                    <input id="identification_type" name="identification_type" value="{{ old('identification_type', $company->identification_type) }}" required class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <div>
                    <label class="text-sm font-bold" for="identification_number">Número de identificación</label>
                    <input id="identification_number" name="identification_number" value="{{ old('identification_number', $company->identification_number) }}" required class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <div>
                    <label class="text-sm font-bold" for="legal_name">Nombre fiscal</label>
                    <input id="legal_name" name="legal_name" value="{{ old('legal_name', $company->legal_name) }}" required class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <div>
                    <label class="text-sm font-bold" for="email">Correo fiscal</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $company->email) }}" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <div>
                    <label class="text-sm font-bold" for="economic_activity">Actividad económica (código)</label>
                    <input id="economic_activity" name="economic_activity" value="{{ old('economic_activity', $config->economic_activity) }}" placeholder="Ej. 1071.9" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-bold" for="fiscal_branch_code">Sucursal fiscal (3 dígitos)</label>
                        <input id="fiscal_branch_code" name="fiscal_branch_code" inputmode="numeric" value="{{ old('fiscal_branch_code', $config->fiscal_branch_code) }}" placeholder="001" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                    </div>
                    <div>
                        <label class="text-sm font-bold" for="fiscal_terminal_code">Terminal fiscal (5 dígitos)</label>
                        <input id="fiscal_terminal_code" name="fiscal_terminal_code" inputmode="numeric" value="{{ old('fiscal_terminal_code', $config->fiscal_terminal_code) }}" placeholder="00001" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                    </div>
                </div>
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black hover:brightness-95">Guardar y continuar</button>
            </form>
        @elseif($step === 'conexion')
            <form method="POST" action="{{ route('fiscal.setup.store', ['step' => 'conexion']) }}" class="mt-4 space-y-4">
                @csrf @method('PUT')
                <div>
                    <label class="text-sm font-bold" for="provider">Proveedor técnico</label>
                    <select id="provider" name="provider" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                        @foreach($providers as $code => $label)
                            <option value="{{ $code }}" @selected(old('provider', $config->provider) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">El proveedor puede cambiar en el futuro sin rehacer este portal.</p>
                </div>
                <div>
                    <span class="text-sm font-bold">Ambiente</span>
                    <div class="mt-2 space-y-2">
                        <label class="flex min-h-[44px] items-center gap-2 rounded-xl border border-slate-300 p-3">
                            <input type="radio" name="environment" value="sandbox" @checked(old('environment', $config->environment) === 'sandbox')> Pruebas (sin validez fiscal)
                        </label>
                        <label class="flex min-h-[44px] items-center gap-2 rounded-xl border border-slate-300 p-3">
                            <input type="radio" name="environment" value="production" @checked(old('environment', $config->environment) === 'production')> Producción (documentos reales)
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Las credenciales de pruebas nunca se copian a producción.</p>
                </div>
                <div>
                    <label class="text-sm font-bold" for="api_key">Llave del proveedor {{ $config->maskedKey() ? '(registrada: ' . $config->maskedKey() . ')' : '' }}</label>
                    <input id="api_key" type="password" name="api_key" autocomplete="off" placeholder="Vacío = conservar la actual" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <div>
                    <label class="text-sm font-bold" for="api_secret">Secreto del proveedor {{ $config->maskedSecret() ? '(registrado)' : '' }}</label>
                    <input id="api_secret" type="password" name="api_secret" autocomplete="off" placeholder="Vacío = conservar el actual" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black hover:brightness-95">Guardar y continuar</button>
            </form>
        @elseif($step === 'verificar')
            <p class="mt-4 text-sm text-slate-600">Comprobamos las credenciales con el proveedor <strong>sin emitir ningún documento</strong> y sin consumir cuota.</p>
            <form method="POST" action="{{ route('fiscal.verify') }}" class="mt-4">
                @csrf
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black hover:brightness-95">Verificar conexión</button>
                <a href="{{ route('fiscal.setup', ['step' => 'preferencias']) }}" class="ml-2 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Omitir por ahora</a>
            </form>
            @if($config->last_verified_at)
                <p class="mt-3 text-sm text-emerald-800">Última verificación: {{ $config->last_verified_at->format('d/m/Y H:i') }}.</p>
            @endif
        @elseif($step === 'preferencias')
            <form method="POST" action="{{ route('fiscal.setup.store', ['step' => 'preferencias']) }}" class="mt-4 space-y-4">
                @csrf @method('PUT')
                <div>
                    <label class="text-sm font-bold" for="default_document">Documento predeterminado</label>
                    <select id="default_document" name="default_document" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                        <option value="01" @selected(old('default_document', $config->default_document) === '01')>Factura electrónica</option>
                        <option value="04" @selected(old('default_document', $config->default_document) === '04')>Tiquete electrónico</option>
                    </select>
                </div>
                <label class="flex min-h-[44px] items-center gap-2 text-sm">
                    <input type="checkbox" name="auto_emit_enabled" value="1" @checked(old('auto_emit_enabled', $config->auto_emit_enabled)) class="h-5 w-5"> Emitir automáticamente desde POS cuando la licencia lo permite
                </label>
                <label class="flex min-h-[44px] items-center gap-2 text-sm">
                    <input type="checkbox" name="notify_receptor_email" value="1" @checked(old('notify_receptor_email', $config->notify_receptor_email)) class="h-5 w-5"> Avisar por correo al receptor cuando esté disponible
                </label>
                <p class="text-xs text-slate-500">El tiquete interno nunca sale a Hacienda ni consume cuota.</p>
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black hover:brightness-95">Guardar y terminar</button>
            </form>
        @else
            <dl class="mt-4 space-y-2 text-sm text-slate-700">
                <div class="flex justify-between gap-3"><dt>Empresa</dt><dd class="font-bold text-right">{{ $company->legal_name ?: $company->trade_name }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Ambiente</dt><dd class="font-bold text-right">{{ $config->isProduction() ? 'Producción' : 'Pruebas' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Verificación</dt><dd class="font-bold text-right">{{ $config->last_verified_at ? $config->last_verified_at->format('d/m/Y H:i') : 'Pendiente' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Estado</dt><dd class="font-bold text-right">{{ $statusLabel }}</dd></div>
            </dl>
            <a href="{{ route('fiscal.index') }}" class="mt-5 inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black hover:brightness-95">Ir al portal fiscal</a>
        @endif
    </section>
</div>
@endsection
