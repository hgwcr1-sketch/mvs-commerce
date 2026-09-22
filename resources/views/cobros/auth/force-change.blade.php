<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Crear tu nueva contraseña — MVS CobrOS</title>
    <meta name="theme-color" content="#14263d">
    <link rel="icon" type="image/png" href="{{ asset('icons/cobros/favicon-32x32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/cobros/apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest-cobros.json') }}">
    <link rel="stylesheet" href="{{ asset('css/cobros-auth.css') }}?v=2">
</head>
<body class="cobros-auth">
    <main class="cobros-login-card">
        <header>
            <img class="cobros-login-logo" src="{{ asset('images/logo-mvs-cobros.png') }}" alt="MVS CobrOS" width="120" height="120">
            <p class="cobros-login-eyebrow">Cobranza Operativa</p>
            <h1>Crea tu nueva contraseña</h1>
            <p class="cobros-login-intro">Por seguridad debes cambiar tu contraseña temporal antes de continuar.</p>
        </header>
        @if($errors->any())
            <div class="cobros-login-errors" role="alert" id="change-errors">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
        <form method="POST" action="{{ route('cobros.password.force.store') }}">
            @csrf
            <div class="cobros-login-field cobros-login-field--password">
                <label for="password">Nueva contraseña</label>
                <div class="cobros-login-password-wrap">
                    <input id="password" type="password" name="password" required autocomplete="new-password"
                           @if($errors->has('password')) aria-invalid="true" aria-describedby="change-errors" @endif>
                    <button type="button" class="cobros-login-toggle" aria-label="Mostrar contraseña" aria-pressed="false" tabindex="-1">
                        <svg class="cobros-login-eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="cobros-login-eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>
            <div class="cobros-login-field cobros-login-field--password">
                <label for="password_confirmation">Confirmar contraseña</label>
                <div class="cobros-login-password-wrap">
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                    <button type="button" class="cobros-login-toggle" aria-label="Mostrar contraseña" aria-pressed="false" tabindex="-1">
                        <svg class="cobros-login-eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="cobros-login-eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>
            <button type="submit">Guardar y continuar</button>
        </form>
        <footer>MVS CobrOS · Cobranza Operativa</footer>
    </main>
    <script>
    (function(){
        document.querySelectorAll('.cobros-login-toggle').forEach(function(btn){
            btn.addEventListener('click',function(){
                var input=this.closest('.cobros-login-password-wrap').querySelector('input');
                var show=input.type==='password';
                input.type=show?'text':'password';
                this.setAttribute('aria-pressed',show?'true':'false');
                this.setAttribute('aria-label',show?'Ocultar contraseña':'Mostrar contraseña');
            });
        });
    })();
    </script>
</body>
</html>
