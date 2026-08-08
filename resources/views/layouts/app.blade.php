<!DOCTYPE html>
<html lang="es">

<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ config('app.name', 'MVS Commerce ERP') }}</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

</head>

<body class="bg-slate-100 antialiased">

<div class="flex h-screen overflow-hidden">

    {{-- SIDEBAR --}}
    @include('components.navigation.sidebar')

    <div class="flex flex-1 flex-col overflow-hidden">

        {{-- HEADER --}}
        @include('components.header')

        {{-- CONTENIDO --}}
        <main
            class="flex-1 overflow-y-auto bg-slate-100">

            <div class="p-6">

                @yield('content')

            </div>

        </main>

    </div>

</div>

</body>

</html>