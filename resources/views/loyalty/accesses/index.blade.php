@extends('layouts.app')

@section('title', 'Accesos al portal de Fidelización')
@section('description', 'Genere, comparta y revoque enlaces seguros (y QR, cuando esté disponible) para que sus clientes consulten su portal de Fidelización.')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-800">Accesos al portal</h1>
        <p class="text-sm text-slate-500">Cada cliente tiene un enlace único y revocable. El enlace no contiene datos personales ni identificadores internos.</p>
    </div>

    @if(session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    @if(session('portal_url'))
        <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-4">
            <p class="text-sm font-semibold text-amber-900">Enlace seguro{{ session('portal_url_customer') ? ' · '.session('portal_url_customer') : '' }}</p>
            <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                <input id="portal-url" type="text" readonly value="{{ session('portal_url') }}" focus
                    class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 font-mono text-xs text-slate-700">
                <button type="button" onclick="navigator.clipboard?.writeText(document.getElementById('portal-url').value); this.textContent = 'Copiado'"
                    class="shrink-0 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Copiar</button>
            </div>
            <p class="mt-2 text-xs font-medium text-amber-800">Guárdelo ahora: por seguridad se muestra una sola vez. Si se pierde, regenere el acceso (el enlace anterior deja de funcionar).</p>
            @unless($qrSupported)
                <p class="mt-1 text-xs text-slate-600">El código QR para este enlace se habilitará al activar la generación local de QR (F33).</p>
            @endunless
        </div>
    @endif

    <x-card><x-slot:header><h2 class="text-lg font-semibold">Generar acceso</h2></x-slot:header>
    <form method="POST" action="{{ route('loyalty.accesses.store') }}" class="grid gap-4 md:grid-cols-3">@csrf
        <div class="md:col-span-2"><label for="customer_id" class="form-label">Cliente<span class="text-red-500">*</span></label><select name="customer_id" id="customer_id" class="form-input" required><option value="">Seleccione un cliente</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>{{ $customer->name }}</option>@endforeach</select>@error('customer_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
        <div class="flex items-end">
            <button class="w-full rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-black hover:bg-amber-600">Generar enlace</button>
        </div>
    </form>
    <p class="mt-3 text-xs text-slate-500">Generar un nuevo acceso revoca automáticamente el anterior del mismo cliente. Los tokens se almacenan únicamente como hash; el enlace completo solo se muestra al generarlo.</p>
    </x-card>

    <x-card><x-slot:header><h2 class="text-lg font-semibold">Accesos activos</h2></x-slot:header>
    <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500"><th class="py-2 pr-4">Cliente</th><th class="py-2 pr-4">Generado</th><th class="py-2 pr-4">Generado por</th><th class="py-2 pr-4">Último uso</th><th class="py-2 pr-4 text-right">Acción</th></tr></thead><tbody>
    @forelse($accesses as $access)<tr class="border-b border-slate-100"><td class="py-2 pr-4 font-semibold text-slate-700">{{ $access->customer?->name }}</td><td class="py-2 pr-4 whitespace-nowrap">{{ $access->created_at->format('d/m/Y H:i') }}</td><td class="py-2 pr-4">{{ $access->user?->name ?? 'Sistema' }}</td><td class="py-2 pr-4 whitespace-nowrap">{{ $access->last_used_at?->format('d/m/Y H:i') ?? 'Nunca' }}</td><td class="py-2 pr-4 text-right">
        <form method="POST" action="{{ route('loyalty.accesses.revoke', $access->customer) }}">@csrf @method('PATCH')
            <button class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">Revocar</button>
        </form>
    </td></tr>
    @empty<tr><td colspan="5" class="py-4 text-slate-500">No hay accesos activos. Genere el primero con el formulario superior.</td></tr>@endforelse
    </tbody></table></div>{{ $accesses->links() }}
    </x-card>
</div>
@endsection
