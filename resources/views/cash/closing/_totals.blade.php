<x-card>
    <x-slot:header><h3 class="font-semibold">Total de conciliación</h3></x-slot:header>
    <div class="grid gap-4 sm:grid-cols-3">
        @foreach(['expected'=>'Total esperado','reported'=>'Total declarado','difference'=>'Diferencia general'] as $key=>$label)
            <div class="min-w-0"><span class="text-sm text-slate-500">{{ $label }}</span><strong class="block break-words text-xl">₡{{ number_format($closingSummary[$key],2,',','.') }}</strong></div>
        @endforeach
    </div>
    <p class="mt-3 text-sm text-slate-500">Efectivo físico más los demás medios. El efectivo conciliado por cobros se muestra por separado y no se suma de nuevo. Revise cada diferencia aunque el total neto sea cero.</p>
</x-card>
