@extends('cobros.layout')
@section('title', 'Cobradores — MVS CobrOS')
@section('content')
<div class="space-y-6">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('cobros.owner.dashboard') }}" class="text-sm font-semibold text-amber-700">← Inicio / Cartera</a>
            <h1 class="mt-2 text-2xl font-bold sm:text-3xl">Cobradores</h1>
            <p class="mt-1 text-sm text-slate-500">
    @if($errors->any())
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    @if($contract['license_mode'] === 'company_cupo' && ! $canCreate)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-bold">Cupo de cobradores alcanzado ({{ $collectorCount }} / {{ $contract['collector_limit'] }}).</p>
            <p class="mt-1">No se puede registrar un cobrador nuevo hasta liberar un cupo (desactivar un cobrador) o ampliar el límite de la licencia.</p>
        </div>
    @endif

    @if($contract['license_mode'] === 'individual')
                    Licencias individuales — cada cobrador tiene su licencia propia.
                @else
                    Licencia por empresa + cupo de cobradores.
                @endif
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if($contract['license_mode'] === 'company_cupo')
                <span class="rounded-full {{ $canCreate ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }} px-3 py-2 text-sm font-bold">
                    {{ $collectorCount }} / {{ $contract['collector_limit'] }} cobradores
                </span>
            @else
                <span class="rounded-full bg-slate-100 px-3 py-2 text-sm font-bold text-slate-800">
                    {{ $collectorCount }} cobradores vinculados
                </span>
            @endif
        </div>
    </header>

    @if($contract['license_mode'] === 'individual')
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <h2 class="font-bold text-amber-900">Modalidad de licencias individuales</h2>
            <p class="mt-2 text-sm text-amber-800">Los cobradores son habilitados individualmente desde el Panel Maestro. Cada cobrador tiene su propia licencia con MVS.</p>
            <p class="mt-1 text-sm text-amber-700">Desde aquí puede ver los cobradores vinculados a su empresa.</p>
        </div>
    @endif

    @if($contract['license_mode'] === 'company_cupo' && $canCreate)
        <form method="POST" action="{{ route('cobros.owner.collectors.store') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            @csrf
            <h2 class="font-bold">Registrar cobrador</h2>
            <p class="mt-2 text-sm text-slate-600">Cupos restantes: <strong>{{ $remainingSlots }}</strong></p>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <label class="text-sm font-semibold">
                    Nombre
                    <input type="text" name="name" required class="mt-2 min-h-11 w-full rounded-xl border px-4" placeholder="Nombre del cobrador">
                </label>
                <label class="text-sm font-semibold">
                    Correo electrónico
                    <input type="email" name="email" required class="mt-2 min-h-11 w-full rounded-xl border px-4" placeholder="correo@ejemplo.com">
                </label>
                <label class="text-sm font-semibold">
                    Teléfono / WhatsApp
                    <input type="text" name="phone" class="mt-2 min-h-11 w-full rounded-xl border px-4" placeholder="Opcional">
                </label>
            </div>
            @if($branches->count())
                <div class="mt-4">
                    <span class="text-sm font-semibold">Sucursales asignadas</span>
                    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 md:grid-cols-3">
                        @foreach($branches as $branch)
                            <label class="flex items-center gap-2 rounded-lg border p-3 text-sm">
                                <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" class="h-4 w-4">
                                {{ $branch->name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="mt-5">
                <button class="min-h-11 rounded-xl bg-amber-500 px-5 font-bold">Registrar cobrador</button>
            </div>
        </form>
    @endif

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
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
            <h2 class="font-bold text-emerald-800">Acceso generado</h2>
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

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-bold">Cobradores registrados</h2>
        @forelse($collectors as $collector)
            <div class="mt-4 rounded-xl border p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <strong class="text-lg">{{ $collector['name'] }}</strong>
                        <span class="ml-2 rounded-full {{ $collector['is_active'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }} px-2 py-1 text-xs font-bold">
                            {{ $collector['is_active'] ? 'Activo' : 'Inactivo' }}
                        </span>
                        <p class="mt-1 text-sm text-slate-500">{{ $collector['email'] }}</p>
                        @if($collector['role'])
                            <p class="mt-1 text-xs text-slate-400">Rol: {{ $collector['role'] }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('cobros.owner.collectors.show', $collector['id']) }}" class="min-h-10 inline-flex items-center rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover">Ver cartera</a>
                        @if($contract['license_mode'] === 'company_cupo')
                            <form method="POST" action="{{ route('cobros.owner.collectors.toggle', $collector['id']) }}">
                                @csrf
                                @method('PATCH')
                                <button class="min-h-10 rounded-lg border px-4 text-sm font-semibold {{ $collector['is_active'] ? 'border-red-300 text-red-700 hover:bg-red-50' : 'border-emerald-300 text-emerald-700 hover:bg-emerald-50' }}">
                                    {{ $collector['is_active'] ? 'Desactivar' : 'Activar' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('cobros.owner.collectors.regenerate', $collector['id']) }}">
                                @csrf
                                <button class="min-h-10 rounded-lg border border-amber-300 px-4 text-sm font-semibold text-amber-700 hover:bg-amber-50" onclick="return confirm('Se generará una nueva contraseña temporal. El cobrador deberá cambiarla en su próximo ingreso.')">Nuevo acceso</button>
                            </form>
                        @endif
                    </div>
                </div>
                @if(count($collector['branches']))
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($collector['branches'] as $branchId => $branchName)
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ $branchName }}</span>
                        @endforeach
                    </div>
                @else
                    <p class="mt-3 text-sm text-slate-400">Sin sucursales asignadas.</p>
                @endif
            </div>
        @empty
            <p class="mt-4 text-sm text-slate-500">No hay cobradores registrados.</p>
        @endforelse
    </section>
</div>
@endsection
