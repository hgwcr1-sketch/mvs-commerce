<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>@yield('title', 'Programa de fidelización')</title>

    @vite(['resources/css/app.css'])
</head>

<body class="min-h-screen bg-slate-100 text-slate-800 antialiased">

    <main class="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6 sm:py-10">

        @yield('content')

    </main>

</body>

</html>
