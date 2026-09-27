@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-2xl space-y-5 pb-24 md:pb-8">
    <section class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-7">
        <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Facturación Electrónica</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-950">Series fiscales</h1>
        <p class="mt-2 text-sm text-slate-600">Último consecutivo conocido por sucursal, terminal y tipo. Las series nunca se resetean, tampoco al cambiar de proveedor. La numeración la sigue asignando el proveedor.</p>

        @if(session('status'))
            <div class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">
                <ul class="list-disc pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($series->isEmpty())
            <p class="mt-4 text-sm text-slate-600">Sin series observadas todavía. Aparecen solas al emitir.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500">
                            <th class="py-2 pr-3">Ambiente</th>
                            <th class="py-2 pr-3">Tipo</th>
                            <th class="py-2 pr-3 hidden md:table-cell">Suc./Term.</th>
                            <th class="py-2 pr-3">Último</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($series as $row)
                            <tr class="border-t border-slate-100">
                                <td class="py-2 pr-3">{{ $row->environment === 'production' ? 'Producción' : 'Pruebas' }}</td>
                                <td class="py-2 pr-3 font-bold">{{ $typeLabels[$row->document_type] ?? $row->document_type }}</td>
                                <td class="py-2 pr-3 hidden md:table-cell">{{ $row->branch_code }}/{{ $row->terminal_code }}</td>
                                <td class="py-2 pr-3 font-mono text-xs">{{ $row->last_consecutivo ?: $row->last_sequence }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $series->links() }}</div>
        @endif
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        <h2 class="text-lg font-bold text-slate-950">Registrar serie conocida</h2>
        <p class="mt-1 text-sm text-slate-600">Solo avanza: nunca acepta un número anterior al observado.</p>
        <form method="POST" action="{{ route('fiscal.series.import') }}" class="mt-4 space-y-4">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-sm font-bold" for="environment">Ambiente</label>
                    <select id="environment" name="environment" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                        <option value="sandbox">Pruebas</option>
                        <option value="production">Producción</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-bold" for="document_type">Tipo</label>
                    <select id="document_type" name="document_type" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                        <option value="01">Factura</option>
                        <option value="04">Tiquete</option>
                        <option value="03">Nota de crédito</option>
                        <option value="02">Nota de débito</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-bold" for="branch_code">Sucursal (3 dígitos)</label>
                    <input id="branch_code" name="branch_code" inputmode="numeric" placeholder="001" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
                <div>
                    <label class="text-sm font-bold" for="terminal_code">Terminal (5 dígitos)</label>
                    <input id="terminal_code" name="terminal_code" inputmode="numeric" placeholder="00001" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px]">
                </div>
            </div>
            <div>
                <label class="text-sm font-bold" for="last_consecutivo">Último consecutivo (20 dígitos)</label>
                <input id="last_consecutivo" name="last_consecutivo" inputmode="numeric" class="mt-1 w-full rounded-xl border border-slate-300 p-3 min-h-[44px] font-mono">
            </div>
            <button type="submit" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-[#D4AF37] px-5 font-bold text-black hover:brightness-95">Registrar serie</button>
        </form>
    </section>
</div>
@endsection
