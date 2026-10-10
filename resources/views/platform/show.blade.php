@extends('layouts.platform')
@section('title', $company->trade_name)
@section('content')
<div class="space-y-6" data-platform-company="{{ $company->id }}">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><a href="{{ route('platform.index') }}" class="text-sm font-semibold text-amber-700">← Empresas</a><h1 class="mt-2 text-2xl font-bold sm:text-3xl">{{ $company->trade_name }}</h1><p class="mt-1 text-sm text-slate-500">Tenant #{{ $company->id }} · supervisión contractual</p><nav class="mt-2 flex flex-wrap gap-2 text-xs font-semibold"><a href="#contrato-cobros" class="inline-flex min-h-11 items-center rounded-full bg-amber-50 px-4 text-amber-800 hover:bg-amber-100">Contrato CobrOS</a><a href="#modulos-contrato" class="inline-flex min-h-11 items-center rounded-full bg-slate-100 px-4 text-slate-700 hover:bg-slate-200">Módulos</a></nav></div><span class="rounded-full bg-amber-100 px-3 py-2 text-sm font-bold text-amber-800">{{ ucfirst($company->license->status) }}</span></header>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-start justify-between gap-4"><div><h2 class="font-bold">Datos del tenant</h2><p class="mt-1 text-sm text-slate-500">Solo lectura. El propietario administra estos datos desde su onboarding/configuración.</p></div><span class="rounded-full px-3 py-1 text-xs font-bold {{ $company->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">{{ $company->is_active ? 'Activa' : 'Inactiva' }}</span></div>
        <dl class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">@foreach(['legal_name'=>'Razón social','identification_number'=>'Identificación','email'=>'Correo empresarial','phone'=>'Teléfono','currency'=>'Moneda','timezone'=>'Zona horaria'] as $field=>$label)<div><dt class="text-slate-500">{{ $label }}</dt><dd class="mt-1 font-semibold">{{ $company->$field ?: 'Sin completar' }}</dd></div>@endforeach<div><dt class="text-slate-500">Propietario tenant</dt><dd class="mt-1 font-semibold">{{ $company->owner?->name ?? 'Sin asignar' }}<br><span class="font-normal">{{ $company->owner?->email }}</span></dd></div></dl>
    </section>

    <section class="grid grid-cols-1 gap-4 lg:grid-cols-2"><article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-bold">Sucursales · solo lectura</h2><div class="mt-4 space-y-3">@forelse($company->branches as $branch)<div class="rounded-xl border p-4"><strong>{{ $branch->name }}</strong><span class="ml-2 text-sm text-slate-500">{{ $branch->code }} · {{ $branch->is_active ? 'Activa' : 'Inactiva' }}</span></div>@empty<p class="text-sm text-slate-500">El propietario aún no completó su primera sucursal.</p>@endforelse</div></article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-bold">Usuarios · solo lectura</h2><div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="text-left text-slate-500"><th class="p-3">Usuario</th><th class="p-3">Rol</th><th class="p-3">Estado</th></tr></thead><tbody>@foreach($company->users as $user)<tr class="border-t"><td class="p-3"><strong>{{ $user->name }}</strong><br>{{ $user->email }}</td><td class="p-3">{{ $company->roles->firstWhere('id', $user->pivot->role_id)?->name ?: 'Sin rol' }}</td><td class="p-3">{{ $user->is_active ? 'Activo' : 'Inactivo' }}</td></tr>@endforeach</tbody></table></div></article>
    </section>

    <form method="POST" action="{{ route('platform.licenses.update', $company) }}" class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm">@csrf @method('PATCH')
        <h2 class="font-bold">Contrato efectivo · {{ ucfirst($company->license->status) }}</h2><p class="mt-2 text-sm text-slate-600">La plantilla propone valores. Guardar overrides modifica solo este tenant.</p>
        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <label class="text-sm font-semibold">Plantilla comercial<select name="license_plan_id" class="mt-2 min-h-11 w-full rounded-xl border px-4"><option value="">Contrato sin plantilla</option>@foreach($licensePlans as $plan)<option value="{{ $plan->id }}" @selected($company->license->license_plan_id === $plan->id)>{{ $plan->name }}</option>@endforeach</select></label>
            <label class="flex min-h-11 items-center gap-3 self-end text-sm font-semibold"><input type="checkbox" name="apply_plan" value="1" class="h-5 w-5"> Reaplicar valores de la plantilla</label>
            <label class="text-sm font-semibold">Estado<select name="status" class="mt-2 min-h-11 w-full rounded-xl border px-4">@foreach(\App\Models\CompanyLicense::STATUSES as $status)<option value="{{ $status }}" @selected($company->license->status === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
            <label class="text-sm font-semibold">Referencia contractual<input name="plan" required value="{{ $company->license->plan }}" class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            @foreach(['starts_at'=>'Inicio','expires_at'=>'Vencimiento','next_renewal_at'=>'Próxima renovación','grace_until'=>'Fin de gracia'] as $field=>$label)<label class="text-sm font-semibold">{{ $label }}<input type="datetime-local" name="{{ $field }}" value="{{ $company->license->$field?->format('Y-m-d\TH:i') }}" class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>@endforeach
            <label class="text-sm font-semibold">Límite usuarios<input type="number" min="1" name="user_limit" value="{{ $company->license->user_limit }}" class="mt-2 min-h-11 w-full rounded-xl border px-4"></label><label class="text-sm font-semibold">Límite sucursales<input type="number" min="1" name="branch_limit" value="{{ $company->license->branch_limit }}" class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
        </div><label class="mt-4 block text-sm font-semibold">Nota de auditoría<textarea name="notes" class="mt-2 w-full rounded-xl border p-4">{{ $company->license->notes }}</textarea></label>
        <div class="mt-5 flex flex-wrap gap-3"><button name="action" value="update" class="min-h-11 rounded-xl bg-amber-500 px-5 font-bold">Guardar contrato</button><button name="action" value="activate" class="min-h-11 rounded-xl border px-5 font-semibold">Activar</button><button name="action" value="renew" class="min-h-11 rounded-xl border border-emerald-300 bg-emerald-50 px-5 font-semibold text-emerald-800">Renovar / extender</button><button name="action" value="suspend" class="min-h-11 rounded-xl border px-5 font-semibold">Suspender</button><button name="action" value="reactivate" class="min-h-11 rounded-xl border px-5 font-semibold">Reactivar</button><button name="action" value="cancel" class="min-h-11 rounded-xl border border-red-300 px-5 font-semibold text-red-700">Cancelar</button></div>
        <div class="mt-6 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr><th class="p-3">Fecha</th><th class="p-3">Acción/cambio</th><th class="p-3">Actor</th></tr></thead><tbody>@forelse($company->license->events as $event)<tr class="border-t"><td class="p-3">{{ $event->created_at->format('d/m/Y H:i') }}</td><td class="p-3">{{ $event->action }} · {{ $event->from_status ?: '—' }} → {{ $event->to_status }} @if($event->changes)<span class="block text-xs text-slate-500">{{ implode(', ', array_keys($event->changes)) }}</span>@endif</td><td class="p-3">{{ $event->actor?->email ?: 'Sistema' }}</td></tr>@empty<tr><td colspan="3" class="p-3">Sin cambios.</td></tr>@endforelse</tbody></table></div>
    </form>

    <form id="modulos-contrato" method="POST" action="{{ route('platform.modules.update', $company) }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">@csrf @method('PATCH')<h2 class="font-bold">Módulos del contrato efectivo</h2><div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">@foreach($moduleCatalog as $key=>$module)<label class="flex min-h-14 items-center gap-3 rounded-xl border p-4 text-sm font-semibold"><input type="checkbox" name="modules[]" value="{{ $key }}" @checked($company->isModuleEnabled($key)) class="h-5 w-5"> {{ $module['label'] }}</label>@endforeach</div><button class="mt-5 min-h-11 rounded-xl bg-slate-950 px-5 font-semibold text-white">Guardar módulos</button></form>

    @php
        $cobrosModuleRow = $company->modules->firstWhere('module_key', 'cobros');
        $cobrosDisabled = $cobrosModuleRow !== null && ! $cobrosModuleRow->is_enabled;
    @endphp
    <form id="contrato-cobros" method="POST" action="{{ route('platform.cobros-contract.update', $company) }}" class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm scroll-mt-24">
        @csrf @method('PATCH')
        <h2 class="font-bold">Contrato CobrOS · Modalidad de licenciamiento</h2>
        <p class="mt-2 text-sm text-slate-600">Configure la modalidad de cobro de licencias para MVS CobrOS. Solo Platform Admin puede modificar este contrato.</p>
        @if($cobrosDisabled)
            <p class="mt-2 rounded-xl bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700">El módulo CobrOS está desactivado para esta empresa. Guardar este contrato lo vuelve a activar.</p>
        @endif
        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <label class="text-sm font-semibold">
                Modalidad
                <select name="license_mode" id="cobros-license-mode" class="mt-2 min-h-11 w-full rounded-xl border px-4" required>
                    <option value="">Seleccionar modalidad</option>
                    <option value="individual" @selected(($cobrosContract['license_mode'] ?? '') === 'individual')>Licencias individuales — cada cobrador tiene su licencia</option>
                    <option value="company_cupo" @selected(($cobrosContract['license_mode'] ?? '') === 'company_cupo')>Licencia por empresa + cupo de cobradores</option>
                </select>
            </label>
            <label class="text-sm font-semibold">
                Límite de cobradores
                <input type="number" name="collector_limit" id="cobros-collector-limit" min="1" max="999" value="{{ $cobrosContract['collector_limit'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-xl border px-4">
                <span id="cobros-limit-help" class="mt-1 block text-xs text-slate-500"></span>
            </label>
            <label class="text-sm font-semibold">
                Licencia administrador/dueño
                <select name="admin_license_charged" class="mt-2 min-h-11 w-full rounded-xl border px-4" required>
                    <option value="1" @selected(($cobrosContract['admin_license_charged'] ?? false) === true)>Se cobra como licencia adicional</option>
                    <option value="0" @selected(($cobrosContract['admin_license_charged'] ?? false) === false)>Incluida sin costo</option>
                </select>
            </label>
        </div>
        <div class="mt-5 flex flex-wrap gap-3">
            <button class="min-h-11 rounded-xl bg-amber-500 px-5 font-bold">Guardar contrato CobrOS</button>
        </div>
        @if($cobrosContract)
        <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm">
            <strong>Estado actual:</strong>
            {{ $cobrosContract['license_mode'] === 'individual' ? 'Licencias individuales' : 'Licencia por empresa + cupo' }} · 
            @if($cobrosContract['collector_limit'])Límite: {{ $cobrosContract['collector_limit'] }} cobradores · @endif
            Licencia admin: {{ $cobrosContract['admin_license_charged'] ? 'Se cobra como licencia adicional' : 'Incluida sin costo' }}
            · Estado: <strong>{{ ucfirst($company->license->status) }}</strong>
            · Vigencia: {{ $company->license->expires_at?->format('d/m/Y') ?? 'Sin vencimiento' }}
            @if($potentialLicenses !== null)
                · <strong>Licencias potenciales: {{ $potentialLicenses }}</strong> (cobradores + administrador/dueño)
            @endif
        </div>
        @endif
    </form>
    <script>
    (function(){
        var mode=document.getElementById('cobros-license-mode');
        var limit=document.getElementById('cobros-collector-limit');
        var help=document.getElementById('cobros-limit-help');
        if(!mode||!limit)return;
        function sync(){
            var isCupo=mode.value==='company_cupo';
            limit.disabled=!isCupo;
            limit.required=isCupo;
            if(!isCupo){limit.value='';}
            help.textContent=isCupo?'Define la cantidad máxima de cobradores que podrá administrar el dueño.':'Los cobradores son habilitados individualmente desde el Panel Maestro.';
        }
        mode.addEventListener('change',sync);
        sync();
    })();
    </script>

    @if($cobrosContract)
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        @if($cobrosContract['license_mode'] === 'individual')
            <h2 class="font-bold">Cobradores con licencia individual</h2>
            <p class="mt-1 text-sm text-slate-500">Los cobradores son habilitados individualmente desde el Panel Maestro. Cada cobrador obtiene/paga su licencia directamente con MVS.</p>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="rounded-xl bg-slate-50 p-4 text-center">
                    <p class="text-sm text-slate-500">Cobradores vinculados</p>
                    <p class="mt-1 text-3xl font-bold">{{ $collectorCount }}</p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 text-center">
                    <p class="text-sm text-slate-500">Sucursales asignadas</p>
                    <p class="mt-1 text-3xl font-bold">{{ $collectors ? collect($collectors)->sum(fn($c) => $c['branches']->count()) : 0 }}</p>
                </div>
            </div>

            <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <h3 class="text-sm font-bold text-amber-800">Registrar cobrador</h3>
                <form method="POST" action="{{ route('platform.collectors.store', $company) }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-4">
                    @csrf
                    <input name="name" required placeholder="Nombre completo" class="min-h-11 rounded-xl border px-4 text-sm">
                    <input name="phone" placeholder="Teléfono / WhatsApp" class="min-h-11 rounded-xl border px-4 text-sm">
                    <input name="email" required type="email" placeholder="Correo electrónico" class="min-h-11 rounded-xl border px-4 text-sm">
                    <button class="min-h-11 rounded-xl bg-amber-500 px-5 font-bold text-white">Crear cobrador</button>
                </form>
            </div>

            @if(session('collector_plain_password'))
                @php
                    $tempPassword = session('collector_plain_password');
                    $tempEmail = session('collector_email');
                    $tempName = session('collector_name');
                    $tempPhone = session('collector_phone');
                    $whatsappPhone = '';
                    if ($tempPhone) {
                        $digits = preg_replace('/\D/', '', $tempPhone);
                        if (str_starts_with($digits, '506')) {
                            $whatsappPhone = $digits;
                        } elseif (strlen($digits) === 8) {
                            $whatsappPhone = '506' . $digits;
                        }
                    }
                    $cobrosLoginUrl = route('cobros.login');
                    $whatsappMessage = "Hola {$tempName}.\nTu acceso a MVS CobrOS está listo.\n\nIngresar:\n{$cobrosLoginUrl}\n\nUsuario: {$tempEmail}\nContraseña temporal: {$tempPassword}\n\nPor seguridad, al ingresar por primera vez deberás crear tu nueva contraseña.";
                    $whatsappUrl = $whatsappPhone ? 'https://wa.me/' . $whatsappPhone . '?text=' . rawurlencode($whatsappMessage) : '';
                @endphp
                <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <h3 class="text-sm font-bold text-emerald-800">Acceso generado</h3>
                    <p class="mt-2 text-sm text-emerald-700">Contraseña temporal para <strong>{{ $tempName }}</strong> ({{ $tempEmail }}):</p>
                    <div class="mt-2 flex items-center gap-2">
                        <code class="rounded-lg bg-white px-3 py-2 text-lg font-bold tracking-wider text-slate-900 border">{{ $tempPassword }}</code>
                        <button type="button" onclick="navigator.clipboard.writeText('{{ $tempPassword }}')" class="min-h-9 rounded-lg bg-emerald-100 px-3 text-xs font-semibold text-emerald-800 hover:bg-emerald-200">Copiar</button>
                    </div>
                    <p class="mt-2 text-xs text-emerald-600">El cobrador deberá cambiar esta contraseña en su primer ingreso.</p>
                    @if($whatsappUrl)
                        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-xl bg-emerald-600 px-5 font-bold text-white hover:bg-emerald-700">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            Enviar acceso por WhatsApp
                        </a>
                    @endif
                </div>
            @endif

            @if(count($collectors))
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500">
                                <th class="p-3">Nombre</th>
                                <th class="p-3 hidden md:table-cell">Correo</th>
                                <th class="p-3">Estado</th>
                                <th class="p-3 hidden lg:table-cell">Licencia</th>
                                <th class="p-3 hidden lg:table-cell">Sucursales</th>
                                <th class="p-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($collectors as $collector)
                                <tr class="border-t">
                                    <td class="p-3 font-semibold">{{ $collector['name'] }}<br><span class="text-xs text-slate-400 md:hidden">{{ $collector['email'] }}</span></td>
                                    <td class="p-3 hidden md:table-cell">{{ $collector['email'] }}</td>
                                    <td class="p-3">
                                        <span class="rounded-full {{ $collector['is_active'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }} px-2 py-1 text-xs font-bold">
                                            {{ $collector['is_active'] ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td class="p-3 hidden lg:table-cell text-xs text-slate-500">
                                        @php
                                            $collectorLicense = $collectorLicenses[$collector['id']] ?? null
                                        @endphp
                                        @if($collectorLicense)
                                            @php
                                                $licenseState = $collectorLicense['state']
                                            @endphp
                                            @php
                                                $licenseBadge = [
                                                'active' => ['bg-emerald-100 text-emerald-800', 'Activa'],
                                                'suspended' => ['bg-red-100 text-red-800', 'Suspendida'],
                                                'expired' => ['bg-amber-100 text-amber-800', 'Vencida'],
                                                'pending' => ['bg-slate-200 text-slate-700', 'Programada'],
                                            ][$licenseState] ?? ['bg-slate-200 text-slate-700', 'Sin licencia']
                                            @endphp
                                            <span class="rounded-full {{ $licenseBadge[0] }} px-2 py-1 text-xs font-bold">{{ $licenseBadge[1] }}</span>
                                            <span class="mt-1 block">
                                                Inicia: {{ $collectorLicense['license']->starts_at?->format('d/m/Y') ?? '—' }}
                                                · Vence: {{ $collectorLicense['license']->expires_at?->format('d/m/Y') ?? '—' }}
                                            </span>
                                            <span class="mt-1 flex flex-wrap gap-1">
                                                <form method="POST" action="{{ route('platform.collectors.license.renew', [$company, $collector['id']]) }}">
                                                    @csrf
                                                    <button class="min-h-9 rounded-lg bg-emerald-50 px-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-100" onclick="return confirm('Se renovará la licencia por 1 año desde hoy.')">Renovar +1 año</button>
                                                </form>
                                                @if($licenseState === 'suspended')
                                                    <form method="POST" action="{{ route('platform.collectors.license.resume', [$company, $collector['id']]) }}">
                                                        @csrf @method('PATCH')
                                                        <button class="min-h-9 rounded-lg bg-slate-100 px-2 text-xs font-semibold hover:bg-slate-200">Reanudar</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('platform.collectors.license.suspend', [$company, $collector['id']]) }}">
                                                        @csrf @method('PATCH')
                                                        <button class="min-h-9 rounded-lg bg-red-50 px-2 text-xs font-semibold text-red-700 hover:bg-red-100" onclick="return confirm('El cobrador dejará de operar CobrOS de inmediato.')">Suspender</button>
                                                    </form>
                                                @endif
                                            </span>
                                        @else
                                            <span class="rounded-full bg-red-100 text-red-800 px-2 py-1 text-xs font-bold">Sin licencia</span>
                                            <form method="POST" action="{{ route('platform.collectors.license.renew', [$company, $collector['id']]) }}" class="mt-1">
                                                @csrf
                                                <button class="min-h-9 rounded-lg bg-emerald-50 px-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">Otorgar 1 año</button>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="p-3 hidden lg:table-cell text-xs text-slate-500">
                                        @forelse($collector['branches'] as $branchName)
                                            <span class="inline-block rounded-full bg-slate-100 px-2 py-0.5 mr-1 mb-1">{{ $branchName }}</span>
                                        @empty
                                            Sin sucursales
                                        @endforelse
                                    </td>
                                    <td class="p-3">
                                        <div class="flex flex-wrap gap-2">
                                            <form method="POST" action="{{ route('platform.collectors.toggle', [$company, $collector['id']]) }}">
                                                @csrf @method('PATCH')
                                                <button class="min-h-9 rounded-lg px-3 text-xs font-semibold {{ $collector['is_active'] ? 'bg-red-50 text-red-700 hover:bg-red-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' }}">
                                                    {{ $collector['is_active'] ? 'Desactivar' : 'Activar' }}
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('platform.collectors.branches', [$company, $collector['id']]) }}" class="inline-flex items-center gap-1">
                                                @csrf @method('PUT')
                                                <select name="branch_ids[]" multiple class="min-h-9 rounded-lg border px-2 text-xs">
                                                    @foreach($company->branches as $branch)
                                                        <option value="{{ $branch->id }}" @selected($collector['branches']->keys()->contains($branch->id))>{{ $branch->name }}</option>
                                                    @endforeach
                                                </select>
                                                <button class="min-h-9 rounded-lg bg-slate-100 px-3 text-xs font-semibold hover:bg-slate-200">Sucursales</button>
                                            </form>
                                            <form method="POST" action="{{ route('platform.collectors.regenerate', [$company, $collector['id']]) }}">
                                                @csrf
                                                <button class="min-h-9 rounded-lg bg-amber-50 px-3 text-xs font-semibold text-amber-800 hover:bg-amber-100" onclick="return confirm('Se generará una nueva contraseña temporal. El cobrador deberá cambiarla en su próximo ingreso.')">Nuevo acceso</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-4 text-sm text-slate-500">No hay cobradores registrados. Use el formulario superior para crear el primero.</p>
            @endif
        @else
            <h2 class="font-bold">Cobradores CobrOS</h2>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-xl bg-slate-50 p-4 text-center">
                    <p class="text-sm text-slate-500">Cobradores registrados</p>
                    <p class="mt-1 text-3xl font-bold">{{ $collectorCount }}</p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 text-center">
                    <p class="text-sm text-slate-500">Límite contratado</p>
                    <p class="mt-1 text-3xl font-bold">{{ $cobrosContract['collector_limit'] }}</p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 text-center">
                    <p class="text-sm text-slate-500">Cupos disponibles</p>
                    <p class="mt-1 text-3xl font-bold {{ $seatUsage >= $cobrosContract['collector_limit'] ? 'text-red-600' : 'text-emerald-600' }}">{{ max(0, $cobrosContract['collector_limit'] - $seatUsage) }}</p>
                    <p class="mt-1 text-xs text-slate-400">Uso total: {{ $seatUsage }}{{ $cobrosContract['admin_license_charged'] ? ' (incluye dueño cobrado)' : ' (dueño gratis no consume cupo)' }}</p>
                </div>
            </div>
            @if(count($collectors))
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500">
                                <th class="p-3">Nombre</th>
                                <th class="p-3">Correo</th>
                                <th class="p-3">Estado</th>
                                <th class="p-3">Rol</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($collectors as $collector)
                                <tr class="border-t">
                                    <td class="p-3 font-semibold">{{ $collector['name'] }}</td>
                                    <td class="p-3">{{ $collector['email'] }}</td>
                                    <td class="p-3">
                                        <span class="rounded-full {{ $collector['is_active'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }} px-2 py-1 text-xs font-bold">
                                            {{ $collector['is_active'] ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td class="p-3">{{ $collector['role'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-4 text-sm text-slate-500">No hay cobradores registrados.</p>
            @endif
            <p class="mt-3 text-xs text-slate-400">El dueño administra sus cobradores desde el portal de CobrOS respetando el cupo contratado.</p>
        @endif
    </section>
    @endif

    {{-- 18B-1: Pagos CobrOS — solicitudes SINPE pendientes (solo Platform Admin) --}}
    <section id="pagos-sinpe" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm scroll-mt-24">
        <h2 class="font-bold">Pagos CobrOS — Pendientes SINPE</h2>
        <p class="mt-2 text-sm text-slate-600">
            Solicitudes de pago reportadas por el pagador, pendientes de verificación humana.
            Enviar un comprobante (incluido WhatsApp) no verifica ni aprueba un pago por sí solo.
        </p>

        @if(session('success'))
            <p class="mt-3 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800" role="status">{{ session('success') }}</p>
        @endif
        @if($errors->any())
            <div class="mt-3 rounded-xl bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
                @foreach($errors->all() as $sinpeError)
                    <p>{{ $sinpeError }}</p>
                @endforeach
            </div>
        @endif

        @if($sinpePending->isEmpty())
            <p class="mt-4 text-sm text-slate-500">No hay solicitudes SINPE pendientes para esta empresa.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500">
                            <th class="p-3">Fecha</th>
                            <th class="p-3">Referencia</th>
                            <th class="p-3">Pagador</th>
                            <th class="p-3">Contrato</th>
                            <th class="p-3">Importe</th>
                            <th class="p-3">Moneda</th>
                            <th class="p-3">Estado</th>
                            <th class="p-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sinpePending as $confirmation)
                            <tr class="border-t">
                                <td class="p-3">{{ optional($confirmation->created_at)->format('d/m/Y H:i') }}</td>
                                <td class="p-3 font-mono">{{ $confirmation->transaction_id }}</td>
                                <td class="p-3">
                                    {{ $confirmation->contract?->payer?->name ?? '—' }}
                                    @if($confirmation->contract?->payer?->email)
                                        <br><span class="text-xs text-slate-500">{{ $confirmation->contract->payer->email }}</span>
                                    @endif
                                </td>
                                <td class="p-3">#{{ $confirmation->cobros_billing_contract_id }}</td>
                                <td class="p-3 text-right">{{ number_format((float) $confirmation->amount, 2) }}</td>
                                <td class="p-3">{{ $confirmation->currency }}</td>
                                <td class="p-3">
                                    <span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-bold text-amber-800">Pendiente</span>
                                </td>
                                <td class="p-3">
                                    <form method="POST" action="{{ route('platform.sinpe.approve', [$company, $confirmation]) }}" class="flex flex-col gap-2">
                                        @csrf
                                        <label class="flex items-start gap-2 text-xs text-slate-600">
                                            <input type="checkbox" name="verified_transfer" value="1" required class="mt-0.5 h-4 w-4">
                                            Verifiqué la transferencia en el banco
                                        </label>
                                        <button class="min-h-11 rounded-xl bg-emerald-600 px-3 text-xs font-bold text-white">Aprobar</button>
                                    </form>
                                    <form method="POST" action="{{ route('platform.sinpe.reject', [$company, $confirmation]) }}" class="mt-2 flex flex-col gap-2">
                                        @csrf
                                        <input type="text" name="notes" required maxlength="500" placeholder="Motivo del rechazo"
                                               class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-xs">
                                        <button class="min-h-11 rounded-xl bg-slate-200 px-3 text-xs font-bold text-slate-700">Rechazar</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-slate-400">La aprobación exige verificación externa de la transferencia en el banco; una captura o un mensaje de WhatsApp no bastan.</p>
        @endif
    </section>

    {{-- 18C-1: Membresía CobrOS — estado para el administrador Maestro autorizado --}}
    <section id="membresia-cobros" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm scroll-mt-24">
        <h2 class="font-bold">Membresía CobrOS</h2>

        @if($membershipContract === null || $membershipState === null)
            <p class="mt-4 text-sm text-slate-500">Esta empresa no tiene un contrato de membresía CobrOS.</p>
        @else
            @php
                $membershipPill = match ($membershipState['status']) {
                    'vigente' => 'bg-emerald-100 text-emerald-800',
                    'proxima_a_vencer' => 'bg-amber-100 text-amber-800',
                    'vencida' => 'bg-red-100 text-red-800',
                    default => 'bg-slate-100 text-slate-700',
                };
                $membershipLabel = match ($membershipState['status']) {
                    'vigente' => 'Vigente',
                    'proxima_a_vencer' => 'Próxima a vencer',
                    'vencida' => 'Vencida',
                    default => 'Pendiente de pago',
                };
            @endphp

            <dl class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-slate-500">Estado</dt>
                    <dd class="mt-1">
                        <span class="rounded-full {{ $membershipPill }} px-3 py-1 text-xs font-bold">{{ $membershipLabel }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Vencimiento efectivo</dt>
                    <dd class="mt-1 font-semibold">{{ $membershipState['expires_on'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Responsable del pago</dt>
                    <dd class="mt-1 font-semibold">
                        {{ $membershipContract->payer?->name ?? '—' }}
                        @if($membershipContract->payer?->email)
                            <br><span class="text-xs text-slate-500">{{ $membershipContract->payer->email }}</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Contrato</dt>
                    <dd class="mt-1 font-semibold">
                        #{{ $membershipContract->id }} · {{ $membershipContract->modality }} · {{ ucfirst($membershipContract->billing_mode) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Vigencia contractual</dt>
                    <dd class="mt-1 font-semibold">
                        {{ optional($membershipContract->valid_from)->format('d/m/Y') }} – {{ optional($membershipContract->valid_until)->format('d/m/Y') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Solicitud de pago pendiente</dt>
                    <dd class="mt-1 font-semibold">{{ $membershipState['has_pending_request'] ? 'Sí' : 'No' }}</dd>
                </div>
            </dl>

            <p class="mt-3 text-xs text-slate-400">
                El estado considera la vigencia contractual y las vigencias de las licencias individuales
                (modalidad {{ $membershipContract->billing_mode === 'individual' ? 'individual' : 'empresa con cupo' }}),
                no únicamente la fecha del contrato.
            </p>

            {{-- 18D-1: avisos de renovación y vencimiento (solo lectura). --}}
            @if(! empty($membershipNotices))
                <div class="mt-4 space-y-3" role="status" data-testid="membresia-avisos">
                    @foreach($membershipNotices as $notice)
                        <div @class([
                            'rounded-xl border px-3 py-2 text-sm',
                            'border-amber-200 bg-amber-50 text-amber-900' => $notice['tone'] === 'warning',
                            'border-red-200 bg-red-50 text-red-900' => $notice['tone'] === 'danger',
                        ])>
                            <p class="font-bold">{{ $notice['title'] }}</p>
                            <p class="mt-1 text-xs">{{ $notice['detail'] }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </section>
</div>
@endsection
