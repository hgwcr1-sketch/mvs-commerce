@extends('layouts.app')
@section('title','Detalle de apartado')
@section('content')
@php($labels=['active'=>'Activo','paid'=>'Pagado','delivered'=>'Entregado','expired'=>'Vencido','cancelled'=>'Cancelado'])
<div class="space-y-5" x-data="layawaySheet(@js($sheet))">
    <div class="flex flex-wrap justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{$layaway->number}}</h1>
            <p class="text-sm text-slate-500">{{$layaway->customer->name}}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="printLayawayReceipt('{{route('mvs.print.ticket.layaway',['layaway'=>$layaway->id],false)}}')" :disabled="busyPrint" class="min-h-[44px] rounded-lg border border-primary px-4 py-2 text-sm font-semibold text-[#806817] hover:bg-primary/10 disabled:opacity-50">
                Comprobante de apartado
            </button>
            <a href="{{route('apartados.index')}}" class="min-h-[44px] inline-flex items-center rounded-lg border px-4 py-2 text-sm font-semibold">Volver</a>
        </div>
    </div>

    <x-card>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>Estado<br><strong>{{$labels[$layaway->status]}}</strong></div>
            <div>Total<br><strong>₡{{number_format((float)$layaway->total,0,',','.')}}</strong></div>
            <div>Abonado<br><strong>₡{{number_format((float)$layaway->paid_total,0,',','.')}}</strong></div>
            <div>Saldo<br><strong>₡{{number_format((float)$layaway->balance_due,0,',','.')}}</strong></div>
            <div>Vencimiento<br><strong>{{$layaway->expires_at->format('d/m/Y')}}</strong></div>
        </div>
    </x-card>

    <x-card>
        <h2 class="mb-3 font-semibold">Productos reservados</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[600px] text-sm">
                <thead>
                    <tr>
                        <th class="p-2 text-left">Producto</th>
                        <th class="p-2 text-right">Cantidad</th>
                        <th class="p-2 text-right">Precio</th>
                        <th class="p-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($layaway->items as $i)
                        <tr class="border-t">
                            <td class="p-2">{{$i->description}}<br>@include('partials.product-variant', ['product' => $i->product])</td>
                            <td class="p-2 text-right">{{number_format((float)$i->quantity,$i->product?->unit?->allows_decimals?4:0,',','.')}}</td>
                            <td class="p-2 text-right">₡{{number_format((float)$i->unit_price,0,',','.')}}</td>
                            <td class="p-2 text-right">₡{{number_format((float)$i->total,0,',','.')}}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    @can('apartados.abonar')
        @if($layaway->status==='active'&&!$layaway->expires_at->isBefore(today()))
            <x-card x-data="mvsMixedPayments(@js($sheet['methods'] ?? []))">
                <h2 class="mb-3 font-semibold">Registrar abono</h2>
                <p class="mb-3 text-sm text-slate-500">Puede dividir el abono entre varios medios de pago. La suma debe ser exacta y no superar el saldo.</p>
                <form method="POST" action="{{route('apartados.payments.store',$layaway)}}" class="space-y-3">
                    @csrf
                    <select name="cash_session_id" class="w-full rounded-lg border border-primary/70 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                        <option value="">Sesión de caja</option>
                        @foreach($sessions as $s)
                            <option value="{{$s->id}}">{{$s->session_number}} — {{$s->cashRegister->name}}</option>
                        @endforeach
                    </select>
                    <input type="hidden" name="amount" :value="total">
                    <div class="space-y-3">
                        <template x-for="(row, i) in rows" :key="i">
                            <div class="grid gap-3 rounded-lg border border-slate-200 p-3 md:grid-cols-5">
                                <div>
                                    <label class="block text-sm">Forma de pago *</label>
                                    <select required x-model="row.method" :name="`payments[${i}][payment_method_id]`" class="w-full rounded-lg border border-primary/70 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                                        <option value="">Seleccione…</option>
                                        @foreach($methods as $m)<option value="{{$m->id}}">{{$m->name}}</option>@endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm">Monto *</label>
                                    <input required type="number" min="0.01" step="0.01" max="{{$layaway->balance_due}}" x-model="row.amount" :name="`payments[${i}][amount]`" class="w-full rounded-lg border border-primary/70 px-3 py-2 text-right font-semibold focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                                </div>
                                <div>
                                    <label class="block text-sm">Referencia</label>
                                    <input x-model="row.reference" :name="`payments[${i}][reference]`" class="w-full rounded-lg border border-primary/70 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                                </div>
                                <div>
                                    <label class="block text-sm">Notas</label>
                                    <input x-model="row.notes" :name="`payments[${i}][notes]`" class="w-full rounded-lg border border-primary/70 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                                </div>
                                <div class="flex items-end">
                                    <button type="button" @click="rows.splice(i, 1)" x-show="rows.length > 1" class="min-h-[44px] rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700 hover:bg-red-50">Quitar</button>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div x-show="allowsChange" x-cloak class="grid gap-3 md:grid-cols-2">
                        <input x-show="allowsChange" x-cloak type="number" min="0.01" step="0.01" inputmode="decimal" name="received_amount" x-model="receivedAmount" placeholder="Monto recibido (efectivo)"
                               class="w-full rounded-lg border border-primary/70 px-3 py-2 text-right font-semibold focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                        <div x-show="allowsChange" x-cloak>
                            <p class="text-sm">Vuelto: <strong class="text-primary" x-text="money(change)"></strong></p>
                            <p x-show="receivedError" class="text-sm font-semibold text-red-600">El monto recibido no puede ser menor que el abono.</p>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <button type="button" @click="rows.push({method:'',amount:'',reference:'',notes:''})" class="min-h-[44px] rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">+ Agregar medio</button>
                        <p class="text-sm font-semibold">Total del abono: ₡<span x-text="total"></span> · Saldo: ₡{{number_format((float)$layaway->balance_due,2,',','.')}}</p>
                    </div>
                    <button class="min-h-[44px] rounded-lg bg-primary px-4 py-2 font-semibold text-slate-950 hover:bg-primary-hover">Registrar abono</button>
                    <p x-show="printMessage" x-cloak x-text="printMessage" class="mt-2 text-sm text-slate-600"></p>
                </form>
            </x-card>
        @endif
    @endcan

    <x-card>
        <h2 class="mb-3 font-semibold">Historial de abonos</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-sm">
                <thead>
                    <tr>
                        <th class="p-2 text-left">Fecha</th>
                        <th class="p-2">Método</th>
                        <th class="p-2">Referencia</th>
                        <th class="p-2">Notas</th>
                        <th class="p-2">Usuario</th>
                        <th class="p-2 text-right">Monto</th>
                        <th class="p-2">Comprobante</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($layaway->payments as $p)
                        <tr class="border-t">
                            <td class="p-2">{{$p->paid_at->format('d/m/Y H:i')}}</td>
                            <td class="p-2 text-center">{{$p->paymentMethod->name}}</td>
                            <td class="p-2 text-center">{{$p->reference??'—'}}</td>
                            <td class="p-2 text-center">{{$p->notes??'—'}}</td>
                            <td class="p-2 text-center">{{$p->user->name}}</td>
                            <td class="p-2 text-right">₡{{number_format((float)$p->amount,0,',','.')}}</td>
                            <td class="p-2 text-center">
                                <button type="button" @click="printPaymentReceipt('{{route('mvs.print.ticket.layaway.payment',['layaway'=>$layaway->id,'payment'=>$p->id],false)}}', {{$p->id}})" :disabled="busyPrint"
                                        class="min-h-[44px] rounded-lg border border-primary/60 px-3 py-1.5 text-xs font-semibold text-[#806817] hover:bg-primary/10 disabled:opacity-50">
                                    Imprimir
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    <div class="flex flex-wrap gap-3">
        @can('apartados.entregar')
            @if($layaway->status==='paid')
                <form method="POST" action="{{route('apartados.deliver',$layaway)}}">
                    @csrf
                    <button class="min-h-[44px] rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700">Entregar y completar venta</button>
                </form>
            @endif
        @endcan
        @can('apartados.cancelar')
            @if(in_array($layaway->status,['active','paid']))
                <form method="POST" action="{{route('apartados.cancel',$layaway)}}" class="flex gap-2">
                    @csrf
                    <input required minlength="3" name="reason" placeholder="Motivo de cancelación" class="w-full rounded-lg border border-primary/70 px-3 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                    <button class="min-h-[44px] rounded-lg bg-red-600 px-4 py-2 font-semibold text-white hover:bg-red-700">Cancelar</button>
                </form>
            @endif
        @endcan
        @if($layaway->sale)
            <a href="{{route('ventas.show',$layaway->sale)}}" class="min-h-[44px] inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-white hover:bg-slate-800">Ver venta</a>
        @endif
    </div>

    @if($errors->any())
        <p class="rounded bg-red-50 p-3 text-red-700">{{$errors->first()}}</p>
    @endif
</div>

<script>
    function mvsMixedPayments(methods = []) {
        return {
            rows: [{method:'',amount:'',reference:'',notes:''}],
            methods,
            receivedAmount: '',
            get total() {
                return this.rows.reduce((sum, row) => sum + (parseFloat(row.amount) || 0), 0).toFixed(2);
            },
            get singleRow() { return this.rows.length === 1 ? this.rows[0] : null; },
            get selectedMethod() {
                if (!this.singleRow || this.singleRow.method === '') return null;
                return this.methods.find(method => Number(method.id) === Number(this.singleRow.method)) || null;
            },
            get allowsChange() { return !!this.selectedMethod?.allows_change && this.rows.length === 1; },
            get rowAmount() { return parseFloat(this.singleRow?.amount) || 0; },
            get received() { return this.allowsChange && this.receivedAmount !== '' ? Number(this.receivedAmount) || 0 : 0; },
            get receivedError() { return this.allowsChange && this.receivedAmount !== '' && Number(this.receivedAmount) < this.rowAmount; },
            get change() { return this.allowsChange ? Math.max(0, this.received - this.rowAmount) : 0; },
            money(value) { return new Intl.NumberFormat('es-CR', { style: 'currency', currency: 'CRC', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(Number(value) || 0); },
        };
    }
    document.addEventListener('alpine:init', () => {
        Alpine.data('layawaySheet', (initialState = {}) => ({
            methods: initialState.methods || [],
            layawayId: initialState.layawayId || null,
            methodId: '',
            receivedAmount: '',
            busyPrint: false,
            printMessage: '',
            initialPrint: initialState.initialPrint || null,
            configUrl: initialState.configUrl || null,
            get selectedMethod() { return this.methods.find(method => Number(method.id) === Number(this.methodId)); },
            get hasReceived() { return !!this.selectedMethod?.allows_change; },
            get received() { return this.hasReceived && this.receivedAmount !== '' ? Number(this.receivedAmount) || 0 : 0; },
            get receivedError() { return this.hasReceived && this.receivedAmount !== '' && Number(this.receivedAmount) < (Number(document.querySelector('input[name="amount"]')?.value) || 0); },
            get change() { return this.hasReceived ? Math.max(0, this.received - (Number(document.querySelector('input[name="amount"]')?.value) || 0)) : 0; },
            money(value) { return new Intl.NumberFormat('es-CR', { style: 'currency', currency: 'CRC', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(Number(value) || 0); },
            async init() {
                if (!this.initialPrint?.url) return;
                try {
                    if (!window.MvsPrint) return;
                    const config = await window.MvsPrint.fetchConfig(this.configUrl || null);
                    if (!config.terminal || !config.terminal.printer_name || !config.auto_print) return;
                    if (this.initialPrint.type === 'payment') {
                        await this.printPaymentReceipt(this.initialPrint.url, this.initialPrint.id);
                    } else {
                        await this.printLayawayReceipt(this.initialPrint.url);
                    }
                } catch {
                    // La impresión automática nunca bloquea la página.
                }
            },
            async printLayawayReceipt(url) {
                if (!window.MvsPrint || this.busyPrint) return;
                this.busyPrint = true;
                this.printMessage = '';
                try {
                    const result = await window.MvsPrint.printLayaway(url, this.layawayId, { reprint: true });
                    this.printMessage = result.success ? 'Comprobante enviado a ' + result.printer : (result.error || 'No fue posible imprimir directamente.');
                } catch {
                    this.printMessage = 'No fue posible imprimir directamente.';
                } finally {
                    this.busyPrint = false;
                }
            },
            async printPaymentReceipt(url, paymentId) {
                if (!window.MvsPrint || this.busyPrint) return;
                this.busyPrint = true;
                this.printMessage = '';
                try {
                    const result = await window.MvsPrint.printLayawayPayment(url, paymentId, { reprint: true });
                    this.printMessage = result.success ? 'Comprobante enviado a ' + result.printer : (result.error || 'No fue posible imprimir directamente.');
                } catch {
                    this.printMessage = 'No fue posible imprimir directamente.';
                } finally {
                    this.busyPrint = false;
                }
            },
        }));
    });
</script>
@endsection
