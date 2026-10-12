<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MVS CobrOS: cobranza profesional para prestamistas y cobradores. Regístrese, pruebe la demo o vea los videos.">
    <meta name="theme-color" content="#D4AF37">
    <title>MVS CobrOS | Cobranza profesional</title>
    <link rel="manifest" href="{{ asset('manifest-cobros.json') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-white antialiased">
    @include('cobros.public-header', ['showLogin' => true])

    @include('cobros.partials.landing-body')
</body>
</html>
