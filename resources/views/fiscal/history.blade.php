@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-2xl space-y-5 pb-24 md:pb-8">
    <section class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-7">
        <p class="text-sm font-bold uppercase tracking-wide text-amber-700">Facturación Electrónica</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-950">Historial de documentos</h1>
        <div class="mt-4 flex flex-wrap gap-2 text-sm">
            <a href="{{ route('fiscal.history') }}" class="min-h-[44px] inline-flex items-center rounded-full px-3 {{ $type === null ? 'bg-[#D4AF37] font-bold text-black' : 'bg-slate-100 text-slate-700' }}">Todos</a>
            @foreach(['01' => 'Facturas', '04' => 'Tiquetes', '03' => 'Notas de crédito', '02' => 'Notas de débito'] as $code => $label)
                <a href="{{ route('fiscal.history', ['type' => $code]) }}" class="min-h-[44px] inline-flex items-center rounded-full px-3 {{ $type === $code ? 'bg-[#D4AF37] font-bold text-black' : 'bg-slate-100 text-slate-700' }}">{{ $label }}</a>
            @endforeach
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
        @if($documents->isEmpty())
            <p class="text-sm text-slate-600">No hay documentos en este filtro. El historial fiscal nunca se borra.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500">
                            <th class="py-2 pr-3">Tipo</th>
                            <th class="py-2 pr-3 hidden md:table-cell">Fecha</th>
                            <th class="py-2 pr-3 hidden md:table-cell">Intento</th>
                            <th class="py-2 pr-3">Estado</th>
                            <th class="py-2 pr-3 hidden lg:table-cell">Clave</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($documents as $doc)
                            <tr class="border-t border-slate-100">
                                <td class="py-2 pr-3 font-bold">{{ $typeLabels[$doc->document_type] ?? $doc->document_type }}</td>
                                <td class="py-2 pr-3 hidden md:table-cell">{{ $doc->created_at->format('d/m/Y H:i') }}</td>
                                <td class="py-2 pr-3 hidden md:table-cell">{{ $doc->attempt_number }}</td>
                                <td class="py-2 pr-3">
                                    {{ $statusLabels[$doc->status] ?? $doc->status }}
                                    @if($doc->status === 'rejected' && $doc->last_error_code)
                                        <span class="block text-xs text-slate-500">({{ $doc->last_error_code }})</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3 hidden lg:table-cell font-mono text-xs">{{ $doc->clave ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $documents->links() }}</div>
        @endif
        <a href="{{ route('fiscal.index') }}" class="mt-4 inline-flex min-h-[44px] items-center text-sm font-bold text-amber-700">Volver al portal fiscal</a>
    </section>
</div>
@endsection
