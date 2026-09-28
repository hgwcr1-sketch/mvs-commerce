@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-2xl space-y-5 pb-24 md:pb-8">
    <section class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-7">
        <p class="text-sm font-bold uppercase tracking-wide text-amber-700">{{ $wizardTitle }}</p>
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
                <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Datos fiscales de la empresa</p>
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
                <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Actividad económica y ubicación fiscal</p>
                <div>
                    <label class="text-sm font-bold" for="economic_activity">Actividad económica (código)</label>
                    <input id="economic_activity" name="economic_activity" value="{{ old('economic_activity', $config->economic_activity) }}" placeholder="Ej. 1071.9" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <div>
                    <span class="text-sm font-bold">Ubicación fiscal</span>
                    <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label class="text-xs font-bold text-slate-600" for="province_id">Provincia</label>
                            <select id="province_id" name="province_id" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                                <option value="">Elegir…</option>
                                @foreach($provinces as $province)
                                    <option value="{{ $province->id }}" @selected((string) old('province_id', $company->province_id) === (string) $province->id)>{{ $province->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-600" for="canton_id">Cantón</label>
                            <select id="canton_id" name="canton_id" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                                <option value="">Elegir…</option>
                                @foreach($provinces as $province)
                                    <optgroup label="{{ $province->name }}">
                                        @foreach($cantons->where('province_id', $province->id) as $canton)
                                            <option value="{{ $canton->id }}" @selected((string) old('canton_id', $company->canton_id) === (string) $canton->id)>{{ $canton->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-600" for="district_id">Distrito</label>
                            <select id="district_id" name="district_id" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                                <option value="">Elegir…</option>
                                @foreach($cantons as $canton)
                                    <optgroup label="{{ $canton->name }}">
                                        @foreach($districts->where('canton_id', $canton->id) as $district)
                                            <option value="{{ $district->id }}" @selected((string) old('district_id', $company->district_id) === (string) $district->id)>{{ $district->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="text-sm font-bold" for="address">Otras señas</label>
                    <input id="address" name="address" value="{{ old('address', $company->address) }}" placeholder="Dirección exacta" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Sucursal / terminal</p>
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
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black shadow-md hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">Guardar y continuar</button>
                <a href="{{ route('fiscal.index') }}" class="ml-2 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Volver al portal</a>
            </form>
        @elseif($step === 'conexion')
            @if($config->hasPending())
                <div class="mt-4 rounded-xl bg-amber-100 p-4 text-sm font-bold text-amber-900">
                    Actualización requerida: hay cambios pendientes de verificación. La conexión actual sigue funcionando.
                </div>
                <form method="POST" action="{{ route('fiscal.connection.discard') }}" class="mt-2">
                    @csrf
                    <button type="submit" class="inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Cancelar cambios pendientes</button>
                </form>
            @endif
            <form method="POST" action="{{ route('fiscal.setup.store', ['step' => 'conexion']) }}" class="mt-4 space-y-4">
                @csrf @method('PUT')
                <p class="text-sm text-slate-600">Conexión fiscal MVS (Hacienda). La conexión la administra MVS; aquí solo registra sus credenciales.</p>
                <div>
                    <span class="text-sm font-bold">Ambiente</span>
                    <div class="mt-2 space-y-2">
                        <label class="flex min-h-[44px] items-center gap-2 rounded-xl border border-slate-300 p-3">
                            <input type="radio" name="environment" value="sandbox" @checked(old('environment', $config->pending_environment ?? $config->environment) === 'sandbox')> Pruebas (sin validez fiscal)
                        </label>
                        <label class="flex min-h-[44px] items-center gap-2 rounded-xl border border-slate-300 p-3">
                            <input type="radio" name="environment" value="production" @checked(old('environment', $config->pending_environment ?? $config->environment) === 'production')> Producción (documentos reales)
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Las credenciales de pruebas nunca se copian a producción.</p>
                </div>
                <label class="flex min-h-[44px] items-center gap-2 text-sm">
                    <input type="checkbox" name="production_confirm" value="1" class="h-5 w-5"> Entiendo que producción emite documentos con validez fiscal (obligatorio para producción)
                </label>
                <div>
                    <label class="text-sm font-bold" for="api_key">Llave de conexión {{ $config->maskedKey() ? '(registrada: ' . $config->maskedKey() . ')' : '' }}</label>
                    <input id="api_key" type="password" name="api_key" autocomplete="off" placeholder="Vacío = conservar la actual" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <div>
                    <label class="text-sm font-bold" for="api_secret">Secreto de conexión {{ $config->maskedSecret() ? '(registrado)' : '' }}</label>
                    <input id="api_secret" type="password" name="api_secret" autocomplete="off" placeholder="Vacío = conservar el actual" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black shadow-md hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">Guardar y verificar</button>
                <a href="{{ route('fiscal.setup', ['step' => 'datos']) }}" class="ml-2 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Anterior</a>
            </form>
            @if($config->hasCredentials())
                <form method="POST" action="{{ route('fiscal.disconnect') }}" class="mt-6 border-t border-slate-200 pt-4" onsubmit="return confirm('¿Retirar la conexión fiscal? El historial se conserva.');">
                    @csrf
                    <label class="flex min-h-[44px] items-center gap-2 text-sm">
                        <input type="checkbox" name="disconnect_confirm" value="1" class="h-5 w-5"> Confirmo que quiero retirar la conexión
                    </label>
                    <button type="submit" class="mt-2 inline-flex min-h-[44px] items-center text-sm font-bold text-rose-700">Retirar conexión</button>
                </form>
            @endif
        @elseif($step === 'verificar')
            @if($config->hasPending())
                <div class="mt-4 rounded-xl bg-amber-100 p-4 text-sm font-bold text-amber-900">Hay cambios pendientes: al verificar con éxito se activan. Si falla, la conexión actual sigue intacta.</div>
            @endif
            <p class="mt-4 text-sm text-slate-600">Comprobamos la conexión con Hacienda <strong>sin emitir ningún documento</strong> y sin consumir cuota.</p>
            <form method="POST" action="{{ route('fiscal.verify') }}" class="mt-4">
                @csrf
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black shadow-md hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">Verificar conexión</button>
                <a href="{{ route('fiscal.setup', ['step' => 'conexion']) }}" class="ml-2 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Anterior</a>
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
                <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black shadow-md hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">Guardar y terminar</button>
                <a href="{{ route('fiscal.setup', ['step' => 'verificar']) }}" class="ml-2 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Anterior</a>
            </form>
        @else
            <dl class="mt-4 space-y-2 text-sm text-slate-700">
                <div class="flex justify-between gap-3"><dt>Empresa</dt><dd class="font-bold text-right">{{ $company->legal_name ?: $company->trade_name }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Ambiente</dt><dd class="font-bold text-right">{{ $config->isProduction() ? 'Producción' : 'Pruebas' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Verificación</dt><dd class="font-bold text-right">{{ $config->last_verified_at ? $config->last_verified_at->format('d/m/Y H:i') : 'Pendiente' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Estado</dt><dd class="font-bold text-right">{{ $statusLabel }}</dd></div>
            </dl>
            <a href="{{ route('fiscal.index') }}" class="mt-5 inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black shadow-md hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">Ir al portal fiscal</a>
            <a href="{{ route('fiscal.setup', ['step' => 'preferencias']) }}" class="ml-2 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Anterior</a>
        @endif
    </section>
</div>
@endsection
