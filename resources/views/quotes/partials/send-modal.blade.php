@php
    $phone = preg_replace('/\D+/', '', (string) (($quote->customer?->phone_country_code ?? '').($quote->customer?->mobile ?: $quote->customer?->phone ?: '')));
    if (strlen($phone) === 8) { $phone = '506'.$phone; }
    $message = "Hola {$quote->customer?->name}, le compartimos la cotización {$quote->quote_number} de {$quote->company->trade_name}.";
    $printUrl = route('cotizaciones.print', $quote);
    // Enlace público temporal (7 días) solo para WhatsApp: vence únicamente el
    // enlace; la cotización conserva su vigencia, estado y datos.
    $shareUrl = URL::temporarySignedRoute('cotizaciones.public.pdf', now()->addDays(7), ['quote' => $quote]);
@endphp
<div x-data="{ open: new URLSearchParams(window.location.search).get('send') === '1' }">
    @if($linkStyle ?? false)
        <a href="#" @click.prevent="open = true" class="underline">Enviar</a>
    @else
        <button type="button" @click="open = true" class="min-h-[44px] rounded-lg border border-primary bg-primary px-4 py-2 font-semibold text-slate-950 hover:bg-primary-hover">Enviar</button>
    @endif
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end bg-slate-950/50 p-3 sm:items-center sm:justify-center" @keydown.escape.window="open = false">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-5 shadow-xl" @click.outside="open = false">
            <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-bold text-slate-950">Enviar cotización</h2><p class="mt-1 text-sm text-slate-600">{{ $quote->quote_number }} · {{ $quote->customer?->name ?? 'Consumidor Final' }}</p></div><button type="button" @click="open = false" class="min-h-[44px] min-w-[44px] rounded-lg text-xl text-slate-600 hover:bg-slate-100" aria-label="Cerrar">×</button></div>
            <div class="mt-5 space-y-3">
                @if($phone)<a class="flex min-h-[44px] items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700" target="_blank" rel="noopener" href="https://wa.me/{{ $phone }}?text={{ rawurlencode($message.' '.$shareUrl) }}">Enviar por WhatsApp</a>@else<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">El cliente no tiene teléfono registrado.</p>@endif
                @if($quote->customer?->email)<a class="flex min-h-[44px] items-center justify-center rounded-lg border border-slate-300 px-4 py-2 font-semibold text-slate-800 hover:bg-slate-50" href="mailto:{{ $quote->customer->email }}?subject={{ rawurlencode('Cotización '.$quote->quote_number) }}&body={{ rawurlencode($message."\n\n".$printUrl) }}">Enviar por correo</a>@else<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">El cliente no tiene correo registrado.</p>@endif
                <a class="flex min-h-[44px] items-center justify-center rounded-lg border border-slate-300 px-4 py-2 font-semibold text-slate-800 hover:bg-slate-50" target="_blank" href="{{ $printUrl }}">Ver documento</a>
            </div>
        </div>
    </div>
</div>
