<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Recuperar Contraseña | MVS Commerce</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center">

    <div class="bg-white rounded-xl shadow-lg p-8 w-full max-w-md">

        <h1 class="text-3xl font-bold text-center mb-2">
            MVS Commerce
        </h1>

        <p class="text-center text-gray-500 mb-2">
            Recuperar contraseña
        </p>

        <p class="text-center text-sm text-gray-500 mb-8">
            Ingresa tu correo electrónico para restablecer tu contraseña.
        </p>

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-red-100 border border-red-300 text-red-700 p-3">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('status'))
    <div class="mb-4 rounded-lg bg-green-100 border border-green-300 text-green-700 p-3">
        {{ session('status') }}
    </div>
@endif

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <div class="mb-6">

                <label class="block mb-2">
                    Correo electrónico
                </label>

                <input
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    class="w-full border rounded-lg px-4 py-3">

            </div>

            <button
                type="submit"
                class="w-full bg-blue-600 text-white py-3 rounded-lg">

                Enviar enlace de recuperación

            </button>

        </form>

        <div class="mt-6 text-center">

            <a
                href="{{ route('login') }}"
                class="text-sm text-gray-600 hover:text-gray-900">

                Volver a iniciar sesión

            </a>

        </div>

    </div>

</body>
</html>