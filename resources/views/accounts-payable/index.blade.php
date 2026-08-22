@extends('layouts.app')
@section('title','Cuentas por pagar')
@section('content')
@php
    $labels=['pending'=>'Pendiente','partial'=>'Parcial','paid'=>'Pagada','overdue'=>'Vencida','cancelled'=>'Cancelada'];
    $classes=['pending'=>'bg-amber-100 text-amber-800','partial'=>'bg-sky-100 text-sky-800','paid'=>'bg-emerald-100 text-emerald-800','overdue'=>'bg-red-100 text-red-800','cancelled'=>'bg-slate-200 text-slate-700'];
@endphp
<div class="space-y-5">
    <div><h1 class="text-2xl font-bold text-slate-800">Cuentas por pagar</h1><p class="text-sm text-slate-500">Obligaciones con proveedores de la sucursal activa.</p></div>

    @can('cuentas_pagar.editar')<form method="POST" action="{{route('cuentas-por-pagar.alert-days.update')}}" class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">@csrf @method('PUT')<div><label for="payable_alert_days" class="mb-1 block text-sm font-medium">Días para alerta próxima</label><select id="payable_alert_days" name="payable_alert_days" class="rounded-lg border-slate-300">@foreach([1,3,5,7,15] as $days)<option value="{{$days}}" @selected((int)auth()->user()->companies()->find(session('active_company_id'))->payable_alert_days===$days)>{{$days}} días</option>@endforeach</select></div><button class="rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white">Guardar configuración</button></form>@endcan

    <form method="GET" action="{{route('cuentas-por-pagar.index')}}" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <input name="search" value="{{$filters['search']??''}}" placeholder="Proveedor, compra o factura" class="rounded-lg border-slate-300 text-sm">
        <select name="status" class="rounded-lg border-slate-300 text-sm"><option value="">Todos los estados</option>@foreach($labels as $value=>$label)<option value="{{$value}}" @selected(($filters['status']??'')===$value)>{{$label}}</option>@endforeach</select>
        <select name="supplier_id" class="rounded-lg border-slate-300 text-sm"><option value="">Todos los proveedores</option>@foreach($suppliers as $supplier)<option value="{{$supplier->id}}" @selected((string)($filters['supplier_id']??'')===(string)$supplier->id)>{{$supplier->name}}</option>@endforeach</select>
        <div class="flex gap-2"><button class="flex-1 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Filtrar</button><a href="{{route('cuentas-por-pagar.index')}}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Limpiar</a></div>
        <label class="text-xs text-slate-600">Fecha desde<input type="date" name="issue_from" value="{{$filters['issue_from']??''}}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        <label class="text-xs text-slate-600">Fecha hasta<input type="date" name="issue_to" value="{{$filters['issue_to']??''}}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        <label class="text-xs text-slate-600">Vencimiento desde<input type="date" name="due_from" value="{{$filters['due_from']??''}}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        <label class="text-xs text-slate-600">Vencimiento hasta<input type="date" name="due_to" value="{{$filters['due_to']??''}}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
    </form>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm"><table class="w-full min-w-[1080px] text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-600"><tr><th class="px-4 py-3 text-left">Proveedor</th><th class="px-4 py-3 text-left">Compra</th><th class="px-4 py-3 text-center">Fecha</th><th class="px-4 py-3 text-center">Vencimiento</th><th class="px-4 py-3 text-right">Monto original</th><th class="px-4 py-3 text-right">Abonado</th><th class="px-4 py-3 text-right">Saldo</th><th class="px-4 py-3 text-center">Estado</th><th></th></tr></thead><tbody class="divide-y divide-slate-100">
        @forelse($accounts as $account) @php($status=$account->effective_status)<tr class="hover:bg-slate-50"><td class="px-4 py-3 font-medium">{{$account->supplier->name}}</td><td class="px-4 py-3">{{$account->purchase->number}}</td><td class="px-4 py-3 text-center">{{$account->issue_date->format('d/m/Y')}}</td><td class="px-4 py-3 text-center">{{$account->due_date->format('d/m/Y')}}</td><td class="px-4 py-3 text-right">₡{{number_format((float)$account->original_amount,0,',','.')}}</td><td class="px-4 py-3 text-right">₡{{number_format((float)$account->paid_amount,0,',','.')}}</td><td class="px-4 py-3 text-right font-semibold">₡{{number_format((float)$account->balance_due,0,',','.')}}</td><td class="px-4 py-3 text-center"><span class="rounded-full px-3 py-1 text-xs font-semibold {{$classes[$status]??'bg-slate-100'}}">{{$labels[$status]??$status}}</span></td><td class="px-4 py-3"><a href="{{route('cuentas-por-pagar.show',$account)}}" class="rounded-lg border border-slate-300 px-3 py-2">Ver</a></td></tr>
        @empty<tr><td colspan="9" class="p-8 text-center text-slate-500">No hay cuentas por pagar con estos filtros.</td></tr>@endforelse
    </tbody></table></div>
    {{$accounts->links()}}
</div>
@endsection
