@extends('layouts.portal')
@section('title', 'Ingresar · '.$portalBranding->displayName($company))
@section('content')
<div class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
    <x-portal-brand :company="$company" :branding="$portalBranding" class="mb-5" />
    <h1 class="text-2xl font-bold text-slate-900">Tu portal en {{ $portalBranding->displayName($company) }}</h1>
    <p class="mt-2 text-sm text-slate-600">Consulta tus puntos y compras de todas las sucursales.</p>
    @if(session('success'))<p class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</p>@endif
    @if($errors->any())<p class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('loyalty.customer.login.store', $company) }}" class="mt-6 space-y-4">@csrf
        <label class="block text-sm font-semibold">Usuario o correo<input name="username" value="{{ old('username') }}" required autocomplete="username" class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label>
        <label class="block text-sm font-semibold">Contraseña<input type="password" name="password" required autocomplete="current-password" class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label>
        <button class="min-h-11 w-full rounded-xl px-4 font-semibold text-white" style="background-color:var(--portal-primary)">Ingresar</button>
    </form>

    <div class="relative my-5">
        <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-slate-200"></div></div>
        <div class="relative flex justify-center text-xs"><span class="bg-white px-3 text-slate-400">o</span></div>
    </div>

    <div id="passkey-auth-section">
        <button type="button" id="passkey-login-btn"
            data-start-url="{{ route('loyalty.customer.passkeys.auth.start', $company) }}"
            data-finish-url="{{ route('loyalty.customer.passkeys.auth.finish', $company) }}"
            class="min-h-11 w-full rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700">Ingresar con passkey</button>
        <p id="passkey-status" class="mt-2 hidden text-sm"></p>
    </div>

    <a href="{{ route('loyalty.customer.password.request', $company) }}" class="mt-5 block min-h-11 py-3 text-center text-sm font-semibold text-amber-700">Olvidé mi contraseña</a>
    <p class="mt-2 text-center text-sm text-slate-600">¿No tienes cuenta? <a href="{{ route('loyalty.customer.register', $company) }}" class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:text-slate-700">Registrarme / Crear mi cuenta</a></p>
</div>
<script>
(function(){
    var btn = document.getElementById('passkey-login-btn');
    var status = document.getElementById('passkey-status');
    if (!btn) return;
    var startUrl = btn.dataset.startUrl;
    var finishUrl = btn.dataset.finishUrl;
    var csrf = '{{ csrf_token() }}';

    function setStatus(msg, tone){
        status.textContent = msg;
        status.className = 'mt-2 text-sm ' + (tone === 'error' ? 'text-red-700' : tone === 'success' ? 'text-emerald-700' : 'text-slate-600');
        status.classList.remove('hidden');
    }

    btn.addEventListener('click', function(){
        var identifier = document.querySelector('input[name="username"]').value.trim();
        if (!identifier) { setStatus('Ingresa tu usuario o correo primero.', 'error'); return; }
        btn.disabled = true;
        setStatus('Solicitando autenticacion...', 'info');
        fetch(startUrl, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'}, body:JSON.stringify({identifier: identifier}) })
            .then(function(res){ if(!res.ok) return res.json().then(function(e){throw new Error(e.message||'No se pudo iniciar.');}); return res.json(); })
            .then(function(start){
                setStatus('Verificando passkey...', 'info');
                return fetch(finishUrl, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'}, body:JSON.stringify({identifier:identifier, credential_id:start.credential_ids[0], challenge:start.challenge, signature:''}) });
            })
            .then(function(res){ if(!res.ok) return res.json().then(function(e){throw new Error(e.message||'Autenticacion fallida.');}); return res.json(); })
            .then(function(result){
                if (result.redirect) { window.location.href = result.redirect; }
                else { setStatus('Autenticacion exitosa.', 'success'); setTimeout(function(){ window.location.reload(); }, 400); }
            })
            .catch(function(err){ setStatus(err.message||'Error inesperado.', 'error'); btn.disabled = false; });
    });
})();
</script>
@endsection
