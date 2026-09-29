@extends('cobros.layout')

@section('title', $customer->name.' — Mi cartera — MVS CobrOS')

@section('content')
<div class="space-y-4">
    <a href="{{ route('cobros.collector.portfolio') }}" class="inline-flex min-h-[44px] items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
        ← Volver a mi cartera
    </a>

    <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-slate-900">{{ $customer->name }}</h1>
                <p class="mt-1 break-words text-sm text-slate-500">
                    {{ $customer->identification ?: 'Sin identificación' }}
                    @if($customer->phone || $customer->mobile)
                        · {{ $customer->phone ?: $customer->mobile }}
                    @endif
                </p>
            </div>
            <div class="text-right">
                <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Saldo total</span>
                <span class="text-2xl font-bold text-slate-900">@include('cobros.components.money', ['amount' => $totalBalance, 'currency' => $company->currency])</span>
            </div>
        </div>
    </div>

    <section aria-labelledby="cxc-open">
        <h2 id="cxc-open" class="text-lg font-semibold text-slate-900">Cuentas por cobrar abiertas ({{ $openAccounts->count() }})</h2>
        @if($openAccounts->isEmpty())
            <p class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">No hay cuentas por cobrar abiertas para este cliente.</p>
        @endif
        <ul class="mt-3 space-y-3">
            @foreach($openAccounts as $account)
                <li class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ $account->sale?->sale_number ?: ('CxC #'.$account->id) }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $account->branch?->name ?: '—' }} · Emitida {{ $account->issued_at->format('d/m/Y') }}</p>
                        </div>
                        <div class="text-right">
                            <span class="block text-xs uppercase tracking-wide text-slate-400">Saldo</span>
                            <span class="text-lg font-bold text-slate-900">@include('cobros.components.money', ['amount' => $account->balance_due, 'currency' => $account->currency_code])</span>
                        </div>
                    </div>
                    <p class="mt-2 text-sm {{ $account->due_date->isPast() ? 'font-semibold text-red-700' : 'text-slate-600' }}">
                        Vence {{ $account->due_date->format('d/m/Y') }}{{ $account->due_date->isPast() ? ' · vencida' : '' }}
                    </p>
                    {{-- 3A: acceso al Gate solo para la cuenta que el Gate declara cobrable. --}}
                    @if(($chargeableAccounts[$customer->id] ?? null) === $account->id)
                        <a href="{{ route('cobros.collector.payments.create', $account) }}"
                           class="mt-3 flex min-h-[48px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-3 text-base font-bold text-slate-950 hover:bg-primary-hover">💰 COBRAR</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="ubicacion" data-geo-section>
        <h2 id="ubicacion" class="text-lg font-semibold text-slate-900">Ubicación</h2>
        @if($customer->hasLocation())
            <button type="button" data-nav-btn data-lat="{{ $customer->latitude }}" data-lng="{{ $customer->longitude }}"
                    class="mt-3 flex min-h-[48px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-3 text-base font-bold text-white hover:bg-primary-hover cursor-pointer">🧭 IR AL CLIENTE</button>
            <div data-nav-fallback class="mt-2 hidden rounded-xl border border-slate-200 bg-white p-3">
                <p class="text-xs text-slate-500">Elige tu app de navegación:</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <a data-nav-app="google" href="#" class="min-h-[44px] inline-flex items-center rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Google Maps</a>
                    <a data-nav-app="waze" href="#" class="min-h-[44px] inline-flex items-center rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Waze</a>
                    <a data-nav-app="apple" href="#" class="min-h-[44px] inline-flex items-center rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Apple Maps</a>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('cobros.collector.portfolio.location', $customer) }}" data-save-location class="mt-3 space-y-2">
                @csrf
                <input type="hidden" name="latitude" value="" data-loc-lat>
                <input type="hidden" name="longitude" value="" data-loc-lng>
                <button type="submit" class="flex min-h-[48px] w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-white px-4 py-3 text-base font-semibold text-slate-700 hover:border-primary hover:text-slate-900 cursor-pointer">📍 GUARDAR UBICACIÓN ACTUAL</button>
                <p data-loc-msg class="text-xs text-slate-500" hidden></p>
            </form>
        @endif
    </section>

    <div class="flex flex-wrap gap-2">
        <a href="{{ route('cobros.visits.customer', $customer) }}"
           class="mt-3 inline-flex min-h-[44px] flex-1 items-center justify-center rounded-xl border border-primary px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-primary/10">Agenda / historial de visitas</a>
    </div>
    <section aria-labelledby="pagos-historicos" class="mt-6">
        <h2 id="pagos-historicos" class="text-lg font-semibold text-slate-900">Pagos históricos</h2>
        @if($payments->isEmpty())
            <p class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">Sin pagos registrados con fuente fiable.</p>
        @else
            <ul class="mt-3 space-y-2">
                @foreach($payments as $payment)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm">
                        <span class="font-semibold text-slate-900">@include('cobros.components.money', ['amount' => $payment->amount, 'currency' => $company->currency])</span>
                        <span class="text-slate-500">{{ $payment->paid_at->format('d/m/Y H:i') }} · {{ $payment->paymentMethod?->name ?: '—' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

<script>
(function () {
    var section = document.querySelector('[data-geo-section]');
    if (!section) { return; }

    var navBtn = section.querySelector('[data-nav-btn]');
    if (navBtn) {
        var lat = navBtn.getAttribute('data-lat');
        var lng = navBtn.getAttribute('data-lng');
        var q = lat + ',' + lng;
        var links = {
            google: 'https://www.google.com/maps/dir/?api=1&destination=' + q,
            waze: 'https://waze.com/ul?ll=' + q + '&navigate=yes',
            apple: 'https://maps.apple.com/?daddr=' + q
        };
        var fallback = section.querySelector('[data-nav-fallback]');
        section.querySelectorAll('[data-nav-app]').forEach(function (a) {
            a.href = links[a.getAttribute('data-nav-app')] || links.google;
            a.target = '_blank';
            a.rel = 'noopener';
        });

        navBtn.addEventListener('click', function () {
            var ua = navigator.userAgent || '';
            var isIOS = /iPad|iPhone|iPod/.test(ua);
            var isAndroid = /Android/.test(ua);
            // Un toque: intenta el esquema nativo del SO (app preferida del dispositivo).
            // Si el SO no lo resuelve, se muestra el fallback de apps (no depende de Waze).
            var scheme = isIOS
                ? 'https://maps.apple.com/?daddr=' + q
                : (isAndroid ? 'geo:' + q + '?q=' + q : links.google);
            try { window.location.href = scheme; } catch (e) { /* fallback abajo */ }
            if (fallback) { fallback.classList.remove('hidden'); }
        });
    }

    var saveForm = section.querySelector('[data-save-location]');
    if (saveForm) {
        var latIn = saveForm.querySelector('[data-loc-lat]');
        var lngIn = saveForm.querySelector('[data-loc-lng]');
        var msg = saveForm.querySelector('[data-loc-msg]');
        saveForm.addEventListener('submit', function (e) {
            if (latIn.value && lngIn.value) { return; }
            e.preventDefault();
            if (!navigator.geolocation) {
                if (msg) { msg.hidden = false; msg.textContent = 'Tu navegador no permite ubicación (contexto no seguro o sin soporte). Puedes continuar sin ella.'; }
                return;
            }
            navigator.geolocation.getCurrentPosition(function (pos) {
                latIn.value = String(pos.coords.latitude);
                lngIn.value = String(pos.coords.longitude);
                saveForm.submit();
            }, function (err) {
                if (!msg) { return; }
                msg.hidden = false;
                if (err && err.code === 1) {
                    msg.textContent = 'Permiso de ubicación denegado. Actívalo en el navegador o continúa sin GPS.';
                } else if (err && err.code === 2) {
                    msg.textContent = 'Ubicación no disponible. Revisa la señal o el GPS del dispositivo y vuelve a intentar.';
                } else if (err && err.code === 3) {
                    msg.textContent = 'Tiempo agotado al buscar la ubicación. Intenta de nuevo o continúa sin GPS.';
                } else {
                    msg.textContent = 'No se pudo obtener tu ubicación. Puedes continuar sin ella.';
                }
            }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
        });
    }
})();
</script>
@endsection
