@extends('layouts.app')

@section('title', 'Configuración de Notificaciones')

@section('content')
<div class="mx-auto max-w-5xl px-3 py-4 sm:px-4 sm:py-6">
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-bold text-slate-900 sm:text-2xl">Configuración de Notificaciones</h1>
            <p class="text-sm text-slate-500">Empresa, rol y usuario. Una preferencia nunca concede acceso.</p>
        </div>
        <a href="{{ route('notifications.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
            Volver al centro
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    <form method="POST" action="{{ route('notifications.preferences.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        @foreach($types as $type => $definition)
            @php
                $typePrefs = $preferences->where('type', $type);
                $companyPref = $typePrefs->first(fn ($pref) => $pref->user_id === null && $pref->role_id === null);
                $overrides = $typePrefs->filter(fn ($pref) => $pref->user_id !== null || $pref->role_id !== null);
            @endphp
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 bg-slate-50 px-4 py-3 sm:px-6">
                    <h2 class="text-sm font-bold text-slate-800">{{ $definition['label'] }}</h2>
                    <p class="text-xs text-slate-500">Módulo origen: {{ $definition['module'] }}</p>
                </div>
                <div class="space-y-3 p-4 sm:p-6">
                    @if($companyPref)
                        <input type="hidden" name="preferences[{{ $type }}_company][type]" value="{{ $type }}">
                        <input type="hidden" name="preferences[{{ $type }}_company][scope]" value="company">
                        <div class="grid grid-cols-1 items-end gap-3 rounded-lg border border-primary/30 bg-primary/5 p-3 sm:grid-cols-4">
                            <div>
                                <p class="text-xs font-semibold text-slate-500">Alcance</p>
                                <p class="text-sm font-medium text-slate-800">Empresa (predeterminado)</p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500" for="pref-{{ $type }}-enabled">Habilitado</label>
                                <select id="pref-{{ $type }}-enabled" name="preferences[{{ $type }}_company][enabled]" class="min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="1" @selected($companyPref->enabled)>Sí</option>
                                    <option value="0" @selected(! $companyPref->enabled)>No</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500" for="pref-{{ $type }}-severity">Severidad mínima</label>
                                <select id="pref-{{ $type }}-severity" name="preferences[{{ $type }}_company][severity_min]" class="min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="INFORMATIVA" @selected($companyPref->severity_min === 'INFORMATIVA')>Informativa</option>
                                    <option value="ATENCION" @selected($companyPref->severity_min === 'ATENCION')>Atención</option>
                                    <option value="CRITICA" @selected($companyPref->severity_min === 'CRITICA')>Crítica</option>
                                </select>
                            </div>
                            <div class="flex items-center sm:justify-end">
                                <span class="inline-flex min-h-11 items-center rounded-full px-3 py-1 text-xs font-bold {{ $companyPref->enabled ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $companyPref->enabled ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                        </div>
                    @endif

                    @foreach($overrides as $pref)
                        <input type="hidden" name="preferences[{{ $type }}_{{ $pref->id }}][type]" value="{{ $type }}">
                        <input type="hidden" name="preferences[{{ $type }}_{{ $pref->id }}][scope]" value="{{ $pref->user_id ? 'user' : 'role' }}">
                        <input type="hidden" name="preferences[{{ $type }}_{{ $pref->id }}][role_id]" value="{{ $pref->role_id }}">
                        <input type="hidden" name="preferences[{{ $type }}_{{ $pref->id }}][user_id]" value="{{ $pref->user_id }}">
                        <div class="grid grid-cols-1 items-end gap-3 rounded-lg border border-slate-100 p-3 sm:grid-cols-4">
                            <div>
                                <p class="text-xs font-semibold text-slate-500">Excepción</p>
                                <p class="text-sm font-medium text-slate-800">
                                    @if($pref->user_id)
                                        Usuario: {{ $pref->user?->name ?? '—' }}
                                    @else
                                        Rol: {{ $pref->role?->name ?? '—' }}
                                    @endif
                                </p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500">Habilitado</label>
                                <select name="preferences[{{ $type }}_{{ $pref->id }}][enabled]" class="min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="1" @selected($pref->enabled)>Sí</option>
                                    <option value="0" @selected(! $pref->enabled)>No</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500">Severidad mínima</label>
                                <select name="preferences[{{ $type }}_{{ $pref->id }}][severity_min]" class="min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="INFORMATIVA" @selected($pref->severity_min === 'INFORMATIVA')>Informativa</option>
                                    <option value="ATENCION" @selected($pref->severity_min === 'ATENCION')>Atención</option>
                                    <option value="CRITICA" @selected($pref->severity_min === 'CRITICA')>Crítica</option>
                                </select>
                            </div>
                            <div class="flex items-center sm:justify-end">
                                <span class="inline-flex min-h-11 items-center rounded-full px-3 py-1 text-xs font-bold {{ $pref->enabled ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $pref->enabled ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 bg-slate-50 px-4 py-3 sm:px-6">
                <h2 class="text-sm font-bold text-slate-800">Agregar excepción de rol o usuario</h2>
                <p class="text-xs text-slate-500">La excepción más específica gana: usuario, luego rol, luego empresa.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-5">
                <div>
                    <label class="block text-xs font-semibold text-slate-500" for="override-type">Tipo</label>
                    <select id="override-type" name="override[type]" class="min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Seleccione</option>
                        @foreach($types as $type => $definition)
                            <option value="{{ $type }}">{{ $definition['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500" for="override-scope">Alcance</label>
                    <select id="override-scope" name="override[scope]" class="min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Seleccione</option>
                        <option value="role">Rol</option>
                        <option value="user">Usuario</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500" for="override-role">Rol</label>
                    <select id="override-role" name="override[role_id]" class="min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Seleccione</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500" for="override-user">Usuario</label>
                    <select id="override-user" name="override[user_id]" class="min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Seleccione</option>
                        @foreach($users as $companyUser)
                            <option value="{{ $companyUser->id }}">{{ $companyUser->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-1">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500" for="override-enabled">Habilitado</label>
                        <select id="override-enabled" name="override[enabled]" class="min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="1">Sí</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500" for="override-severity">Severidad mínima</label>
                        <select id="override-severity" name="override[severity_min]" class="min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="INFORMATIVA">Informativa</option>
                            <option value="ATENCION">Atención</option>
                            <option value="CRITICA">Crítica</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-6 py-2.5 text-sm font-bold text-slate-900 hover:bg-primary-hover">
                Guardar preferencias
            </button>
        </div>
    </form>
</div>
@endsection
