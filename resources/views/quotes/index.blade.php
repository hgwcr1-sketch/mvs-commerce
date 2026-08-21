@extends('layouts.app')
@section('title', 'Cotizaciones')
@section('content')
<div class="space-y-5">
    <div class="flex items-center justify-between"><h1 class="text-2xl font-bold">Cotizaciones</h1>@can('cotizaciones.crear')<a href="{{ route('pos.index') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Nueva cotización</a>@endcan</div>
    <x-card>
        <div class="overflow-x-auto"><table class="min-w-full divide-y"><thead><tr><th class="p-3 text-left">Número</th><th class="p-3 text-left">Fecha</th><th class="p-3 text-left">Cliente</th><th class="p-3 text-left">Estado</th><th class="p-3 text-right">Total</th><th></th></tr></thead><tbody class="divide-y">
        @forelse($quotes as $quote)<tr><td class="p-3 font-semibold">{{ $quote->quote_number }}</td><td class="p-3">{{ $quote->created_at->format('d/m/Y H:i') }}</td><td class="p-3">{{ $quote->customer?->name ?? 'Consumidor Final' }}</td><td class="p-3">{{ ['active'=>'Activa','converted'=>'Convertida','cancelled'=>'Cancelada'][$quote->status] ?? $quote->status }}</td><td class="p-3 text-right">₡{{ number_format((float)$quote->total, 2, ',', '.') }}</td><td class="p-3 text-right"><a class="underline" href="{{ route('cotizaciones.show', $quote) }}">Ver</a></td></tr>@empty<tr><td colspan="6" class="p-8 text-center text-slate-500">No hay cotizaciones.</td></tr>@endforelse
        </tbody></table></div><div class="mt-4">{{ $quotes->links() }}</div>
    </x-card>
</div>
@endsection
