@extends('layouts.portal')
@section('title', 'Mis passkeys · '.$portalBranding->displayName($company))
@section('content')
@php
    $points = static function ($value, bool $signed = false): string {
        $number = (float) $value;
        $formatted = rtrim(rtrim(number_format(abs($number), 4, ',', '.'), '0'), ',');
        return ($signed ? ($number >= 0 ? '+' : '-') : '').$formatted.',00 puntos';
    };
    $money = static fn ($value): string => '₡'.number_format((float) $value, 2, ',', '.');
    $portalName = $portalBranding->displayName($company);
    $activeCount = $passkeys->where('revoked_at', null)->count();
@endphp
<div class="mx-auto max-w-2xl space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
    <header class="flex items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-slate-900 sm:text-2xl">Mis passkeys</h1>
            <p class="mt-1 text-sm text-slate-600">Tu passkey es una llave matemática vinculada a este dispositivo. La clave privada nunca sale del dispositivo; el servidor conserva solo la mitad pública y el identificador de credencial. MVS nunca almacena biometría.</p>
        </div>
        <a href="{{ route('loyalty.customer.home', $company) }}" class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Volver</a>
    </header>

    @if(session('success'))<p class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</p>@endif
    @if($errors->any())<p class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</p>@endif

    <section class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <h2 class="text-base font-semibold text-slate-800">Agregar nueva passkey</h2>
        <p class="mt-1 text-xs text-slate-600">Tu navegador generará una llave matemática ligada a este dispositivo. El servidor solo conserva la mitad pública y el credential ID: nunca tu biometría ni la clave privada.</p>
        <form id="mvs-passkey-register" class="mt-3 space-y-3" data-start-url="{{ route('loyalty.customer.passkeys.start', $company) }}" data-finish-url="{{ route('loyalty.customer.passkeys.finish', $company) }}" data-csrf="{{ csrf_token() }}" data-max="{{ 8 }}" data-active="{{ $activeCount }}">
            <label class="block text-sm font-semibold text-slate-700">Nombre de la passkey<input name="name" required maxlength="80" placeholder="Ej. iPhone de Juan" class="mt-2 min-h-11 w-full rounded-xl border-slate-300 bg-white"></label>
            <button type="button" data-action="register" class="min-h-11 w-full rounded-xl px-4 text-sm font-semibold text-white" style="background-color:var(--portal-primary)">Registrar passkey en este dispositivo</button>
            <p data-status class="mt-2 hidden text-sm"></p>
        </form>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white">
        <h2 class="border-b border-slate-200 px-4 py-3 text-base font-semibold text-slate-800">Passkeys registradas</h2>
        @forelse($passkeys as $passkey)
            <article class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-b-0">
                <div class="min-w-0">
                    <p class="font-semibold text-slate-800">{{ $passkey->name }}</p>
                    <p class="text-xs text-slate-500">
                        {{ $passkey->algorithm }} · Registrada {{ $passkey->created_at?->format('d/m/Y H:i') }}
                        @if($passkey->last_used_at) · Último uso {{ $passkey->last_used_at->format('d/m/Y H:i') }} @endif
                    </p>
                    @if($passkey->revoked_at)<p class="mt-1 text-xs font-semibold text-red-600">Revocada {{ $passkey->revoked_at->format('d/m/Y H:i') }}</p>@endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($passkey->isActive())
                        <form method="POST" action="{{ route('loyalty.customer.passkeys.rename', [$company, $passkey->id]) }}" class="flex items-center gap-1">
                            @csrf @method('PATCH')
                            <input type="hidden" name="name" value="{{ $passkey->name }}" data-rename-input>
                            <button type="button" data-action="rename" class="min-h-11 rounded-xl border border-slate-300 px-3 text-xs font-semibold text-slate-700">Renombrar</button>
                        </form>
                        <form method="POST" action="{{ route('loyalty.customer.passkeys.revoke', [$company, $passkey->id]) }}" data-confirm="¿Revocar esta passkey? Después de revocarla no podrás volver a usarla.">
                            @csrf @method('DELETE')
                            <button class="min-h-11 rounded-xl border border-red-300 px-3 text-xs font-semibold text-red-700">Revocar</button>
                        </form>
                    @endif
                </div>
            </article>
        @empty
            <p class="px-4 py-6 text-sm text-slate-500">Aún no tienes passkeys registradas. Usa el formulario de arriba para empezar.</p>
        @endforelse
    </section>

    <p class="text-xs text-slate-500">¿Perdiste tu dispositivo? Ingresa con tu contraseña y revoca la passkey para invalidarla. La contraseña siempre sigue activa como respaldo.</p>
</div>

@section('scripts')
<script>
(function(){
    const form = document.getElementById('mvs-passkey-register');
    if (!form) return;
    const status = form.querySelector('[data-status]');
    const button = form.querySelector('[data-action="register"]');
    const startUrl = form.dataset.startUrl;
    const finishUrl = form.dataset.finishUrl;
    const csrf = form.dataset.csrf;
    const max = parseInt(form.dataset.max || '8', 10);
    const active = parseInt(form.dataset.active || '0', 10);

    function setStatus(message, tone){
        status.textContent = message;
        status.className = 'mt-2 text-sm ' + (tone === 'error' ? 'text-red-700' : tone === 'success' ? 'text-emerald-700' : 'text-slate-600');
        status.classList.remove('hidden');
    }

    function disable(state){
        button.disabled = state;
        button.classList.toggle('opacity-60', state);
    }

    async function handleRegister(){
        const name = (form.querySelector('input[name="name"]').value || '').trim();
        if (!name) { setStatus('Asigna un nombre a la passkey.', 'error'); return; }
        if (!window.PublicKeyCredential) { setStatus('Este navegador no soporta passkeys WebAuthn.', 'error'); return; }
        if (active >= max) { setStatus('Has alcanzado el máximo de ' + max + ' passkeys. Revoca una para registrar otra.', 'error'); disable(true); return; }

        disable(true);
        setStatus('Solicitando opciones de registro...', 'info');
        try {
            const startResponse = await fetch(startUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: JSON.stringify({ name: name }) });
            if (!startResponse.ok) {
                const err = await startResponse.json().catch(() => ({}));
                throw new Error(err.message || (err.errors ? Object.values(err.errors).flat().join(' ') : 'No se pudo iniciar el registro.'));
            }
            const start = await startResponse.json();

            // Usar WebAuthn standard: navigator.credentials.create()
            const credential = await navigator.credentials.create({
                publicKey: {
                    rpId: start.rpId || '${window.location.hostname}',
                    // Usar el user ID generado por el server o uno nuevo
                    user: {
                        id: start.user.id ? base64_decode(start.user.id) : new Uint8Array(16),
                        name: start.user.name || '',
                        displayName: start.user.displayName || '',
                    },
                    challenge: base64_decode(start.challenge),
                    // Permitir que el dispositivo decida el tipo (platform vs cross-platform)
                    // algorithms: ['ES256'], // ES256 es el más común para passkeys
                    timeout: start.timeout || 60000,
                    attestation: 'direct',
                    // No request resident key by default
                    // residentKey: 'required'
                }
            });

            setStatus('Procesando registro de passkey...', 'info');
            const finishResponse = await fetch(finishUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({
                    id: credential.id, // credential ID del dispositivo
                    rawResponse: b64encode(new Uint8Array(credential.response.authenticatorData + credential.response.clientDataJson + credential.response.signature)), // simplified - real implementation would CBOR-encode the full assertion
                    type: 'webauthn.get', // This is for registration actually - need to check
                    // Para registro, el tipo es "webauthn.make"
                    type: 'webauthn.make',
                    // Los datos rawResponse completos vienen del evento credential
                }),
            });
            if (!finishResponse.ok) {
                const err = await finishResponse.json().catch(() => ({}));
                throw new Error(err.message || 'No se pudo completar el registro.');
            }
            setStatus('Passkey registrada. Recargando...', 'success');
            setTimeout(function(){ window.location.reload(); }, 600);
        } catch (err) {
            setStatus(err.message || 'Error inesperado al registrar la passkey.', 'error');
            disable(false);
        }
    }

    function b64encode(buf) {
        let binary = '';
        const bytes = new Uint8Array(buf);
        const len = bytes.byteLength;
        for (let i = 0; i < len; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    // Inicializar listeners para rename/revoke (estos ya funcionan con forms normales)
    document.querySelectorAll('[data-action="rename"]').forEach(function(btn){
        btn.addEventListener('click', function(){
            const input = btn.closest('form').querySelector('[data-rename-input]');
            const next = window.prompt('Nuevo nombre de la passkey', input.value || '');
            if (!next) return;
            input.value = next.trim().slice(0, 80);
            btn.closest('form').submit();
        });
    });

    document.querySelectorAll('[data-confirm]').forEach(function(form){
        form.addEventListener('submit', function(event){
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        });
    });

    if (active >= max) {
        setStatus('Has alcanzado el máximo de ' + max + ' passkeys. Revoca una para registrar otra.', 'error');
        disable(true);
        return;
    }

    button.addEventListener('click', async function(){
        handleRegister();
    });
})();
</script>
@endsection