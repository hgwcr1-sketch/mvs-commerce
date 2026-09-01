<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
<img src="{{ $logoUrl }}"
     width="180"
     alt="MVS Commerce"
     style="display: inline-block; width: 100%; max-width: 180px; height: auto; border: 0; outline: none; text-decoration: none;">
<span style="display: block; margin-top: 8px; color: #475569; font-family: Arial, sans-serif; font-size: 13px; font-weight: 700; letter-spacing: .04em;">
MVS Commerce
</span>
</x-mail::header>
</x-slot:header>

# Hola {{ $ownerName }}

MVS creó el acceso comercial de su empresa.

Defina una contraseña segura para activar su cuenta y completar el onboarding de su tenant.

<x-mail::button :url="$activationUrl" color="primary">
Activar mi acceso
</x-mail::button>

Este enlace es personal, expirable y de un solo uso.

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} MVS Commerce. Todos los derechos reservados.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
