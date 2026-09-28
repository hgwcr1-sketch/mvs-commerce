@props(['sale'])

<div x-data="mvsReprint(@js(route('mvs.print.ticket', $sale, false)), @js($sale->id))" class="min-w-0 max-w-full space-y-2">
    <button type="button" @click="reprint()" :disabled="busy"
            class="min-h-11 rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50">
        <span x-text="busy ? 'Enviando…' : 'Reimprimir'">Reimprimir</span>
    </button>
    <p x-show="message" x-cloak x-text="message" role="status" aria-live="polite" class="max-w-xs whitespace-normal break-words text-sm text-slate-700"></p>
    <a x-show="failed" x-cloak href="{{ route('pos.receipt', $sale) }}" target="_blank" rel="noopener"
       class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-100">
        Usar impresión del navegador
    </a>
    <noscript><a href="{{ route('pos.receipt', $sale) }}" target="_blank" rel="noopener">Usar impresión del navegador</a></noscript>
</div>
