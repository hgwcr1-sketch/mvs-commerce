<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión | MVS Commerce</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center">

    <div class="bg-white rounded-xl shadow-lg p-8 w-full max-w-md">

        <h1 class="text-3xl font-bold text-center mb-2">
            MVS Commerce
        </h1>

        <p class="text-center text-gray-500 mb-8">
            Iniciar sesión
        </p>

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-red-100 border border-red-300 text-red-700 p-3">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}">

            @csrf

            <div class="mb-4">
                <label class="block mb-2">Correo electrónico</label>

                <input
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    class="w-full border rounded-lg px-4 py-3">
            </div>

            <div class="mb-6">
    <label class="block mb-2">Contraseña</label>

    <div class="relative">

        <input
            id="password"
            type="password"
            name="password"
            required
            class="w-full border rounded-lg px-4 py-3 pr-12">

        <button
            type="button"
            onclick="togglePassword()"
            class="absolute inset-y-0 right-0 flex items-center px-4 text-gray-500 hover:text-gray-800"
            aria-label="Mostrar u ocultar contraseña">

            <svg id="eyeIcon"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.8"
                stroke="currentColor"
                class="w-5 h-5">

                <path stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z" />

                <path stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />

            </svg>

        </button>

    </div>
</div>
            <div class="mb-6 flex items-center justify-between">

    <div class="flex items-center">
        <input
            type="checkbox"
            name="remember"
            id="remember"
            class="h-4 w-4 rounded border-gray-300">

        <label for="remember" class="ml-2 text-sm text-gray-600">
            Recordarme
        </label>
    </div>

    <a
        href="{{ route('password.request') }}"
        class="text-sm text-gray-600 hover:text-gray-900">

        ¿Olvidó su contraseña?

    </a>

</div>

<button
    type="submit"
    class="w-full bg-blue-600 text-white py-3 rounded-lg">

    Ingresar

</button>

</form>

    </div>

    <script>
    function togglePassword() {
        const password = document.getElementById('password');

        if (password.type === 'password') {
            password.type = 'text';
        } else {
            password.type = 'password';
        }
    }
</script>

</body>
</html>