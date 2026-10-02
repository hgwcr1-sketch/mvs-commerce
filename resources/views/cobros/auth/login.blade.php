<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Ingresar — MVS CobrOS</title>
    <meta name="theme-color" content="#14263d">
    <link rel="icon" type="image/png" href="{{ asset('icons/cobros/favicon-32x32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/cobros/apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest-cobros.json') }}">
    <link rel="stylesheet" href="{{ asset('css/cobros-auth.css') }}?v=3">
</head>
<body class="cobros-auth">
    <main class="cobros-login-card">
        <header>
            <img class="cobros-login-logo" src="{{ asset('images/logo-mvs-cobros.png') }}" alt="MVS CobrOS" width="120" height="120">
            <p class="cobros-login-eyebrow">Cobranza Operativa</p>
            <h1>Bienvenido a MVS CobrOS</h1>
            <p class="cobros-login-intro">Ingrese con sus credenciales para continuar.</p>
        </header>
        @if($errors->any())
            <div class="cobros-login-errors" role="alert" id="login-errors">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
        <form method="POST" action="{{ route('cobros.login.store') }}">
            @csrf
            <div class="cobros-login-field">
                <label for="email">Correo electrónico</label>
                <input id="email" type="email" name="email" value="{{ is_string(old('email')) ? old('email') : '' }}"
                       required autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false"
                       @if($errors->has('email')) aria-invalid="true" aria-describedby="login-errors" @endif>
            </div>
            <div class="cobros-login-field cobros-login-field--password">
                <label for="password">Contraseña</label>
                <input id="password" type="password" name="password" required autocomplete="current-password"
                       @if($errors->has('password')) aria-invalid="true" aria-describedby="login-errors" @endif>
                <button type="button" class="cobros-login-toggle" aria-label="Mostrar contraseña" aria-pressed="false" tabindex="-1">
                    <svg class="cobros-login-eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="cobros-login-eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
            </div>
            <label class="cobros-login-remember" for="remember">
                <input id="remember" type="checkbox" name="remember" value="1"> Recordarme
            </label>
            <button type="submit">Ingresar</button>
        </form>
        {{-- Instalación explícita del PWA CobrOS (botón oculto hasta que el navegador la ofrece). --}}
        <div id="cobros-install-root" class="cobros-login-install">
            <button type="button" id="cobros-install" class="hidden">
                <span id="cobros-install-label">Instalar CobrOS</span>
            </button>
        </div>
        <footer>MVS CobrOS · Cobranza Operativa</footer>
    </main>
    @vite(['resources/js/cobros-pwa.js'])
    <script>
    (function(){
        var btn=document.querySelector('.cobros-login-toggle');
        if(!btn)return;
        btn.addEventListener('click',function(){
            var input=document.getElementById('password');
            var show=input.type==='password';
            input.type=show?'text':'password';
            btn.setAttribute('aria-pressed',show?'true':'false');
            btn.setAttribute('aria-label',show?'Ocultar contraseña':'Mostrar contraseña');
        });
    })();
    </script>
</body>
</html>
