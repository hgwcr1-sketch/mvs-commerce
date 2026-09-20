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
    <link rel="stylesheet" href="{{ asset('css/cobros-auth.css') }}?v=1">
</head>
<body class="cobros-auth">
    <main class="cobros-login-card">
        <header>
            <img class="cobros-login-logo" src="{{ asset('images/logo-mvs-cobros.png') }}" alt="MVS CobrOS" width="96" height="96">
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
            <div class="cobros-login-field">
                <label for="password">Contraseña</label>
                <input id="password" type="password" name="password" required autocomplete="current-password"
                       @if($errors->has('password')) aria-invalid="true" aria-describedby="login-errors" @endif>
            </div>
            <label class="cobros-login-remember" for="remember">
                <input id="remember" type="checkbox" name="remember" value="1"> Recordarme
            </label>
            <button type="submit">Ingresar</button>
        </form>
        <footer>MVS CobrOS · Cobranza Operativa</footer>
    </main>
</body>
</html>
