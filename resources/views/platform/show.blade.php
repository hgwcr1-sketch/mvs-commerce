@extends('layouts.platform')
@section('title', $company->trade_name)
@section('content')
<div class="space-y-6" data-platform-company="{{ $company->id }}">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><a href="{{ route('platform.index') }}" class="text-sm font-semibold text-amber-700">← Empresas</a><h1 class="mt-2 text-2xl font-bold sm:text-3xl">{{ $company->trade_name }}</h1><p class="mt-1 text-sm text-slate-500">Tenant #{{ $company->id }} · datos administrativos</p></div><span class="rounded-full px-3 py-2 text-sm font-bold {{ $company->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">{{ $company->is_active ? 'Activa' : 'Inactiva' }}</span></header>
    <form method="POST" action="{{ route('platform.companies.update', $company) }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">@csrf @method('PATCH')
        <h2 class="font-bold">Configuración básica</h2><div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach(['trade_name' => 'Nombre comercial', 'legal_name' => 'Razón social', 'email' => 'Correo', 'phone' => 'Teléfono', 'currency' => 'Moneda', 'timezone' => 'Zona horaria'] as $field => $label)
                <label class="text-sm font-semibold">{{ $label }}<input name="{{ $field }}" value="{{ old($field, $company->$field) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-4" {{ in_array($field, ['trade_name','currency','timezone']) ? 'required' : '' }}></label>
            @endforeach
            <label class="flex min-h-11 items-center gap-3 self-end text-sm font-semibold"><input type="checkbox" name="is_active" value="1" @checked($company->is_active) class="h-5 w-5 rounded"> Empresa activa</label>
        </div><button class="mt-5 min-h-11 rounded-xl bg-slate-950 px-5 font-semibold text-white">Guardar configuración</button>
    </form>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-bold">Sucursales</h2><div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
        @foreach($company->branches as $branch)<article class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-4"><div><strong class="block">{{ $branch->name }}</strong><span class="text-sm text-slate-500">{{ $branch->code }}</span></div><form method="POST" action="{{ route('platform.branches.update', [$company, $branch]) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $branch->is_active ? 0 : 1 }}"><button class="min-h-11 rounded-xl border px-4 text-sm font-semibold">{{ $branch->is_active ? 'Desactivar' : 'Activar' }}</button></form></article>@endforeach
    </div></section>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-bold">Usuarios y accesos</h2><div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="border-b text-left text-slate-500"><th class="p-3">Usuario</th><th class="p-3">Correo</th><th class="p-3">Rol</th><th class="p-3">Estado</th><th class="p-3">Acción</th></tr></thead><tbody>
        @foreach($company->users as $user)<tr class="border-b border-slate-100"><td class="p-3 font-semibold">{{ $user->name }}</td><td class="p-3">{{ $user->email }}</td><td class="p-3">{{ $company->roles->firstWhere('id', $user->pivot->role_id)?->name ?: 'Sin rol' }}</td><td class="p-3">{{ $user->is_active ? 'Activo' : 'Inactivo' }}</td><td class="p-3"><form method="POST" action="{{ route('platform.users.update', [$company, $user]) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}"><button class="min-h-11 rounded-xl border px-4 font-semibold">{{ $user->is_active ? 'Desactivar' : 'Activar' }}</button></form></td></tr>@endforeach
    </tbody></table></div></section>
    <section class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5"><h2 class="font-bold">Módulos contratados</h2><p class="mt-2 text-sm text-slate-600">La habilitación real por empresa se incorpora en P02. Esta sección no simula ni concede módulos.</p></section>
</div>
@endsection
