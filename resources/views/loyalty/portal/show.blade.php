@extends('layouts.portal')

@section('title', 'Programa de fidelización · '.$company->trade_name)

@section('content')

@php
    $points = static function ($value, bool $signed = false): string {
        $number = (float) $value;
        $formatted = rtrim(rtrim(number_format(abs($number), 4, ',', '.'), '0'), ',');
        return ($signed ? ($number >= 0 ? '+' : '-') : '').$formatted.' puntos';
    };
    $money = static fn ($value): string => '₡'.number_format((float) $value, 2, ',', '.');
    $decimal = static function ($value): string {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    };
@endphp

<div class="space-y-6">

    @if(session('success'))<p class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</p>@endif

    {{-- Encabezado con la identidad visual de la empresa (F31) --}}
    <header class="rounded-2xl px-5 py-6 text-white shadow-sm sm:px-8" style="background-color:var(--portal-primary)">
        <div class="flex items-center gap-4">
            @if($company->logo)
                <img src="{{ asset('storage/'.$company->logo) }}" alt="Logo de {{ $company->trade_name }}" class="h-14 w-14 shrink-0 rounded-xl border border-white/10 bg-white object-contain p-1.5">
            @else
                <div data-brand-initial class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl text-xl font-bold text-white" style="background-color:var(--portal-accent)" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($company->trade_name), 0, 1)) }}</div>
            @endif
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold" style="color:var(--portal-accent)">{{ $company->trade_name }}</p>
                <h1 class="mt-0.5 truncate text-xl font-bold sm:text-2xl">Programa de fidelización</h1>
            </div>
        </div>
        <p class="mt-4 truncate border-t border-white/10 pt-3 text-sm text-slate-300">{{ $customer->name }}</p>
        @if($portalSetting->welcome_message)<p class="mt-3 text-sm text-slate-200">{{ $portalSetting->welcome_message }}</p>@endif
        @if($customerAuthenticated ?? false)
            <form method="POST" action="{{ route('loyalty.customer.logout', $company) }}" class="mt-3">@csrf<button class="min-h-10 text-sm font-semibold text-amber-300">Cerrar sesión</button></form>
        @endif
    </header>

    @unless($module_active)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
            El programa de fidelización no está disponible por el momento.
        </div>
    @endunless

    @if($module_active)
        {{-- Saldo actual --}}
        <section aria-label="Saldo actual" class="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm sm:p-8">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Saldo actual</h2>
            <p class="mt-3 text-4xl font-bold text-slate-900 sm:text-5xl">{{ $points($balance_points) }}</p>
            @if($balance_money !== null)
                <p class="mt-2 text-base text-slate-600">Equivale a <span class="font-semibold text-emerald-700">{{ $money($balance_money) }}</span></p>
            @endif
            <div class="mt-4 grid grid-cols-2 gap-3 text-left"><div class="rounded-xl bg-emerald-50 p-3"><span class="text-xs text-emerald-700">Puntos ganados</span><strong class="mt-1 block text-emerald-900">{{ $points($total_earned) }}</strong></div><div class="rounded-xl bg-amber-50 p-3"><span class="text-xs text-amber-700">Puntos utilizados</span><strong class="mt-1 block text-amber-900">{{ $points($total_redeemed) }}</strong></div></div>
            @if($redemption && $redemption['minimum_enabled'])
                <p class="mt-3 text-sm text-slate-600">@if($redemption['eligible']) Ya puedes canjear puntos. @else Te faltan {{ $money($redemption['missing_money']) }} para alcanzar el mínimo de {{ $money($redemption['minimum_money']) }}. @endif</p>
            @endif
            @if($expiration)
                <div class="mt-4 rounded-xl p-3 text-sm {{ $expiration['near'] ? 'bg-red-50 text-red-800' : 'bg-slate-50 text-slate-700' }}">
                    Tus puntos vencen el <strong>{{ $expiration['date']->format('d/m/Y') }}</strong> ({{ $expiration['days'] }} días restantes). Una compra que califique renueva la vigencia por {{ $expiration['months'] }} meses según las reglas del programa.
                </div>
            @endif
        </section>

        @if(!$credentialExists && ($customerAuthenticated ?? false))
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5"><h2 class="font-semibold text-amber-950">Activa tu acceso con contraseña</h2><form method="POST" action="{{ route('loyalty.customer.activate') }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">@csrf<input name="username" required placeholder="Usuario" class="min-h-11 w-full rounded-xl border-amber-300"><input type="email" name="email" required value="{{ $customer->email }}" placeholder="Correo" class="min-h-11 w-full rounded-xl border-amber-300"><input type="password" name="password" required placeholder="Contraseña" class="min-h-11 w-full rounded-xl border-amber-300"><input type="password" name="password_confirmation" required placeholder="Confirmar contraseña" class="min-h-11 w-full rounded-xl border-amber-300"><button class="min-h-11 rounded-xl bg-amber-600 px-4 font-semibold text-white sm:col-span-2">Guardar acceso</button></form></section>
        @endif

        @if($offers->isNotEmpty())
            <section aria-label="Ofertas" class="space-y-3"><h2 class="text-lg font-semibold">Ofertas</h2><div class="grid grid-cols-1 gap-3 sm:grid-cols-2">@foreach($offers as $product)<article class="rounded-xl border bg-white p-4"><h3 class="font-semibold">{{ $product->name }}</h3><p class="mt-2 text-sm"><s>{{ $money($product->sale_price) }}</s> <strong class="text-emerald-700">{{ $money($product->special_price) }}</strong></p></article>@endforeach</div></section>
        @endif

        @if($posts->isNotEmpty())
            <section aria-label="Novedades" class="space-y-3"><h2 class="text-lg font-semibold">Novedades y avisos</h2><div class="grid grid-cols-1 gap-4 sm:grid-cols-2">@foreach($posts as $post)<article class="overflow-hidden rounded-xl border bg-white {{ $post->is_featured ? 'ring-2 ring-amber-400' : '' }}"><x-loyalty-post-image :post="$post" image-class="h-48 w-full" class="rounded-none border-0 border-b" /><div class="p-4"><p class="text-xs font-bold uppercase text-amber-700">{{ ['new_product' => 'Nuevo producto', 'offer' => 'Oferta', 'promotion' => 'Promoción', 'notice' => 'Aviso'][$post->type] }}</p><h3 class="mt-1 font-semibold">{{ $post->title }}</h3>@if($post->message)<p class="mt-2 text-sm text-slate-600">{{ $post->message }}</p>@endif @if($post->product)<p class="mt-2 text-sm text-slate-500">{{ $post->product->name }}</p><p class="font-semibold">{{ $money($post->product->special_price ?? $post->product->sale_price) }}</p>@endif</div></article>@endforeach</div></section>
        @endif

        @if($portalLinks->isNotEmpty())
            <nav aria-label="Acciones comerciales" class="grid grid-cols-1 gap-3 sm:grid-cols-2">@foreach($portalLinks as $link)<a href="{{ $link->url }}" rel="noopener noreferrer" target="_blank" class="min-h-11 rounded-xl bg-amber-600 px-4 py-3 text-center font-semibold text-white">{{ $link->label }}</a>@endforeach</nav>
        @endif

        @if($recommended->isNotEmpty())
            <section aria-label="Para ti" class="space-y-3"><h2 class="text-lg font-semibold">Para ti</h2><p class="text-sm text-slate-500">Basado en categorías de tus compras anteriores.</p><div class="grid grid-cols-1 gap-3 sm:grid-cols-2">@foreach($recommended as $product)<article class="rounded-xl border bg-white p-4"><h3 class="font-semibold">{{ $product->name }}</h3><p class="mt-2 text-sm">{{ $money($product->special_price ?? $product->sale_price) }}</p></article>@endforeach</div></section>
        @endif

        {{-- Promociones vigentes: contenido administrable de la empresa (F35) --}}
        <section aria-label="Promociones" class="space-y-3">
            <h2 class="text-lg font-semibold text-slate-800">Promociones vigentes</h2>
            @forelse($promotions as $promotion)
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <h3 class="font-semibold text-slate-800">{{ $promotion->title }}</h3>
                    @if($promotion->description)
                        <p class="mt-1 break-words text-sm text-slate-600">{{ $promotion->description }}</p>
                    @endif
                    <p class="mt-1 text-xs text-slate-500">
                        Del {{ $promotion->starts_at->timezone($company->timezone ?: config('app.timezone'))->format('d/m/Y') }}
                        al {{ $promotion->ends_at->timezone($company->timezone ?: config('app.timezone'))->format('d/m/Y') }}
                    </p>
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500">No hay promociones vigentes.</p>
            @endforelse
        </section>

        {{-- Multiplicadores de puntos vigentes (mecanismo F12) --}}
        <section aria-label="Multiplicadores de puntos" class="space-y-3">
            <h2 class="text-lg font-semibold text-slate-800">Multiplicadores de puntos</h2>
            @forelse($multipliers as $multiplier)
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-slate-800">{{ $multiplier->name }}</h3>
                        <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800">x{{ $decimal($multiplier->multiplier) }} puntos</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Vigente hasta el {{ $multiplier->ends_at->timezone($company->timezone ?: config('app.timezone'))->format('d/m/Y') }}
                        @if($multiplier->branch)
                            · {{ $multiplier->branch->name }}
                        @endif
                    </p>
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500">No hay multiplicadores vigentes por el momento.</p>
            @endforelse
        </section>

        {{-- Premios disponibles --}}
        <section aria-label="Premios" class="space-y-3">
            <h2 class="text-lg font-semibold text-slate-800">Premios disponibles</h2>
            @forelse($rewards as $reward)
                <article class="flex items-start justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <div class="min-w-0">
                        <h3 class="font-semibold text-slate-800">{{ $reward->name }}</h3>
                        @if($reward->description)
                            <p class="mt-1 break-words text-sm text-slate-600">{{ $reward->description }}</p>
                        @endif
                        @if((float) $reward->missing_points > 0)<p class="mt-1 text-xs font-semibold text-amber-700">Te faltan {{ $points($reward->missing_points) }}</p>@endif
                    </div>
                    <span class="shrink-0 rounded-full bg-slate-900 px-3 py-1.5 text-xs font-bold text-white">{{ $points($reward->points_cost) }}</span>
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500">No hay premios disponibles por el momento.</p>
            @endforelse
        </section>
    @endif

    {{-- Historial de movimientos --}}
    <section aria-label="Historial de movimientos" class="space-y-3">
        <h2 class="text-lg font-semibold text-slate-800">Historial de movimientos</h2>

        @forelse($movements as $movement)
            <article class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ \App\Models\LoyaltyMovement::LABELS[$movement->type] ?? $movement->type }}</span>
                        <span class="text-xs text-slate-500">{{ $movement->effective_at?->format('d/m/Y H:i') }}</span>
                    </div>
                    <p class="mt-1.5 break-words text-sm font-medium text-slate-700">{{ $movement->description }}</p>
                    @if($movement->branch)<p class="mt-1 text-xs font-medium text-slate-500">Sucursal: {{ $movement->branch->name }}</p>@endif
                    @if($movement->source_type && $movement->source_id)
                        <p class="mt-0.5 text-xs text-slate-400">{{ class_basename($movement->source_type) }} #{{ $movement->source_id }}</p>
                    @endif
                </div>
                <p class="shrink-0 text-right text-base font-bold {{ (float) $movement->points >= 0 ? 'text-emerald-700' : 'text-red-700' }}">{{ $points($movement->points, true) }}</p>
            </article>
        @empty
            <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500">Aún no tienes movimientos.</p>
        @endforelse

        <div>{{ $movements->onEachSide(0)->links() }}</div>
    </section>

    <section aria-label="Historial de compras" class="space-y-3"><h2 class="text-lg font-semibold">Historial de compras</h2>
        @forelse($sales as $sale)
            <article class="rounded-xl border bg-white p-4"><div class="flex flex-wrap items-center justify-between gap-2"><div><h3 class="font-semibold">{{ $sale->sale_number }}</h3><p class="text-xs text-slate-500">{{ $sale->completed_at?->format('d/m/Y H:i') }} · {{ $sale->branch->name }}</p></div><strong>{{ $money($sale->total) }}</strong></div><div class="mt-3 flex flex-col gap-2 sm:flex-row"><a href="{{ route('loyalty.customer.receipt.pdf', [$company, $sale]) }}" class="min-h-11 rounded-xl border px-4 py-3 text-center text-sm font-semibold">Descargar PDF</a><form method="POST" action="{{ route('loyalty.customer.receipt.mail', [$company, $sale]) }}" class="flex flex-1 flex-col gap-2 sm:flex-row">@csrf<input type="email" name="email" required value="{{ $customer->email }}" class="min-h-11 min-w-0 flex-1 rounded-xl border-slate-300"><button class="min-h-11 rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white">Reenviar</button></form></div></article>
        @empty<p class="rounded-xl border border-dashed bg-white p-6 text-center text-sm text-slate-500">Aún no tienes compras.</p>@endforelse
        <div>{{ $sales->onEachSide(0)->links() }}</div>
    </section>

    @if($customerAuthenticated ?? false)
        <section aria-label="Perfil" class="rounded-2xl border bg-white p-5"><h2 class="text-lg font-semibold">Perfil y preferencias</h2><form method="POST" action="{{ route('loyalty.customer.profile', $company) }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">@csrf @method('PATCH')<label class="text-sm font-semibold">Teléfono<input name="phone" value="{{ $customer->phone }}" class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label><label class="text-sm font-semibold">Celular<input name="mobile" value="{{ $customer->mobile }}" class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label><label class="text-sm font-semibold sm:col-span-2">Correo<input type="email" name="email" value="{{ $customer->email }}" class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label><label class="flex min-h-11 items-center gap-3 text-sm sm:col-span-2"><input type="checkbox" name="accepts_email_invoice" value="1" @checked($customer->accepts_email_invoice)> Deseo recibir comprobantes por correo</label><button class="min-h-11 rounded-xl bg-slate-900 px-4 font-semibold text-white sm:col-span-2">Guardar preferencias</button></form></section>
    @endif

</div>
@endsection
