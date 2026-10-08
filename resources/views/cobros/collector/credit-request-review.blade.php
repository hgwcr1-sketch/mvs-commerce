@extends('cobros.layout')

@section('title', 'Revisar solicitud — MVS CobrOS')

@section('content')
<div class="space-y-6">
    <a href="{{ route('cobros.collector.credit-requests.create') }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-amber-700">← Volver al formulario</a>

    <div>
        <h1 class="text-2xl font-bold sm:text-3xl">Revisar solicitud</h1>
        @php($reviewRequiresOwner = \App\Models\CobrosCollectorAuthorityPolicy::requiresOwner((int) session('active_company_id'), (int) auth()->id()))
        <p class="mt-1 text-sm text-slate-500">
            @if($reviewRequiresOwner)
                Confirme los datos. Al enviar, la solicitud queda <strong>Pendiente de revisión</strong> del dueño.
            @else
                Confirme los datos. Después continuará con la revisión de la información del cliente.
            @endif
        </p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <dl class="divide-y divide-slate-100 text-sm">
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Tipo de solicitante</dt><dd class="text-right">{{ ($data['applicant_type'] ?? 'nuevo') === 'existente' ? 'Cliente existente' : 'Cliente nuevo' }}</dd></div>
            @if(!empty($customer))
                <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Cliente vinculado</dt><dd class="text-right">{{ $customer->name }} ({{ $customer->identification }}){{ $customer->phone ? ' · '.$customer->phone : '' }}</dd></div>
            @endif
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Tipo de identificación</dt><dd class="text-right">{{ $data['identification_type'] ?? '—' }}</dd></div>
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Identificación</dt><dd class="text-right">{{ $data['identification'] }}</dd></div>
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Nombre</dt><dd class="text-right">{{ $data['name'] }}</dd></div>
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Teléfono</dt><dd class="text-right">{{ $data['phone'] ?? '—' }}</dd></div>
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Dirección / referencia</dt><dd class="text-right">{{ $data['address'] ?? '—' }}</dd></div>
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Monto solicitado</dt>
                <dd class="text-right">@include('cobros.components.money', ['amount' => $data['amount_requested'], 'currency' => $company->currency])</dd></div>
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Ubicación GPS</dt>
                <dd class="text-right">{{ (isset($data['latitude']) && $data['latitude'] !== '') ? 'Capturada' : 'Sin capturar (opcional)' }}</dd></div>
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Foto identificación — Frente</dt>
                <dd class="text-right font-semibold text-emerald-700">{{ $front ? 'Adjunta ✓' : 'Se conserva la foto actual ✓' }}</dd></div>
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Foto identificación — Reverso</dt>
                <dd class="text-right font-semibold text-emerald-700">{{ $back ? 'Adjunta ✓' : 'Se conserva la foto actual ✓' }}</dd></div>
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Notas</dt><dd class="text-right">{{ $data['notes'] ?? '—' }}</dd></div>
            <div class="flex justify-between gap-4 py-3"><dt class="font-semibold text-slate-500">Ruta</dt><dd class="text-right">{{ $route->name }}</dd></div>
        </dl>

        <form method="POST" action="{{ route('cobros.collector.credit-requests.store') }}" class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
            @csrf
            <input type="hidden" name="review_token" value="{{ $token }}">
            @foreach($data as $key => $value)
                @if(is_scalar($value))
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
            <a href="{{ route('cobros.collector.credit-requests.create') }}"
               class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 px-6 text-sm font-semibold hover:bg-slate-50">Editar</a>
            <button type="submit" class="min-h-[48px] rounded-xl bg-primary px-6 text-base font-bold text-white hover:bg-primary-hover">{{ $reviewRequiresOwner ? 'ENVIAR A REVISIÓN' : 'CONTINUAR A REVISIÓN' }}</button>
        </form>
    </div>
</div>
@endsection
