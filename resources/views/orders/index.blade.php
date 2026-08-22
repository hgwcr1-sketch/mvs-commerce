@extends('layouts.app')
@section('title', 'Pedidos internos')
@section('content')
@php($statuses = ['pending'=>'Pendiente','approved'=>'Aprobado','partial'=>'Parcial','rejected'=>'Rechazado','in_purchase'=>'En compra','completed'=>'Completado','cancelled'=>'Cancelado'])
<div class="space-y-5">
    <div><h1 class="text-2xl font-bold">Pedidos internos</h1><p class="text-sm text-slate-500">Solicitudes internas de abastecimiento de la sucursal activa.</p></div>
    <x-card>
        <form method="GET" class="mb-5 grid gap-3 md:grid-cols-4">
            <input name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Número o solicitante" class="rounded-lg border px-3 py-2">
            <select name="status" class="rounded-lg border px-3 py-2"><option value="">Todos los estados</option>@foreach($statuses as $value=>$label)<option value="{{$value}}" @selected(($filters['status']??'')===$value)>{{$label}}</option>@endforeach</select>
            <input type="date" name="date" value="{{ $filters['date'] ?? '' }}" class="rounded-lg border px-3 py-2">
            <div class="flex gap-2"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Buscar</button><a href="{{route('pedidos.index')}}" class="rounded-lg border px-4 py-2">Limpiar</a></div>
        </form>
        <div class="overflow-x-auto"><table class="min-w-full divide-y text-sm"><thead><tr><th class="p-3 text-left">Número</th><th class="p-3 text-left">Fecha</th><th class="p-3 text-left">Sucursal</th><th class="p-3 text-left">Solicitante</th><th class="p-3 text-left">Estado</th><th class="p-3 text-right">Acciones</th></tr></thead><tbody class="divide-y">
        @forelse($orders as $order)<tr><td class="p-3 font-semibold">{{$order->number}}</td><td class="p-3">{{$order->created_at->format('d/m/Y H:i')}}</td><td class="p-3">{{$order->branch->name}}</td><td class="p-3">{{$order->requester->name}}</td><td class="p-3">{{$order->status_label}}</td><td class="p-3 text-right"><a class="underline" href="{{route('pedidos.show',$order)}}">Ver</a></td></tr>
        @empty<tr><td colspan="6" class="p-8 text-center text-slate-500">No hay pedidos internos.</td></tr>@endforelse
        </tbody></table></div><div class="mt-4">{{$orders->links()}}</div>
    </x-card>
</div>
@endsection
