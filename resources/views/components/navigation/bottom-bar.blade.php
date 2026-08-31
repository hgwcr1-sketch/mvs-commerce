@php
    $bottomNavCompany = \App\Models\Company::find(session('active_company_id'));
    $bottomNavItems = [];

    if ($bottomNavCompany && auth()->user()->hasPermission('dashboard.admin', $bottomNavCompany) || $bottomNavCompany && auth()->user()->hasPermission('dashboard.ver', $bottomNavCompany)) {
        $bottomNavItems[] = ['route' => 'dashboard', 'pattern' => 'dashboard', 'label' => 'Inicio', 'icon' => 'home'];
    }

    if ($bottomNavCompany && auth()->user()->hasPermission('pos.acceder', $bottomNavCompany)) {
        $bottomNavItems[] = ['route' => 'pos.index', 'pattern' => 'pos.*', 'label' => 'POS', 'icon' => 'cart'];
    }

    if ($bottomNavCompany && auth()->user()->hasPermission('productos.ver', $bottomNavCompany)) {
        $bottomNavItems[] = ['route' => 'productos.index', 'pattern' => 'productos.*', 'label' => 'Productos', 'icon' => 'cube'];
    }

    if ($bottomNavCompany && (auth()->user()->hasPermission('caja.abrir', $bottomNavCompany) || auth()->user()->hasPermission('caja.ver', $bottomNavCompany))) {
        $bottomNavItems[] = ['route' => 'cash.index', 'pattern' => 'cash.*', 'label' => 'Caja', 'icon' => 'cash'];
    }
@endphp

<nav id="bottom-nav"
     class="fixed inset-x-0 bottom-0 z-40 grid border-t border-slate-200 bg-white shadow-[0_-4px_16px_rgba(15,23,42,0.08)] transition-transform duration-200"
     style="grid-template-columns: repeat({{ count($bottomNavItems) + 1 }}, minmax(0, 1fr)); padding-bottom: env(safe-area-inset-bottom);"
     aria-label="Navegación principal">

    @foreach($bottomNavItems as $item)
        <a href="{{ route($item['route']) }}"
           title="{{ $item['label'] }}"
           @class([
               'flex min-h-[56px] flex-col items-center justify-center gap-1 text-[11px] font-medium transition',
               'text-amber-600' => request()->routeIs($item['pattern']),
               'text-slate-500 hover:text-slate-700' => ! request()->routeIs($item['pattern']),
           ])>

            @switch($item['icon'])
                @case('home')
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 10.5L12 3l9 7.5M5.25 9.75V21h13.5V9.75"/>
                    </svg>
                @break
                @case('cart')
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.4l1.6 10.4a2 2 0 002 1.7h8.9a2 2 0 002-1.6l1.35-6H6.1M9 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm9 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"/>
                    </svg>
                @break
                @case('cube')
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 16V8l-9-5-9 5v8l9 5 9-5z"/>
                    </svg>
                @break
                @case('cash')
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75l11.03-11.03M3.75 12h7.5L3.75 4.5v15l7.5-7.5h-7.5m13.5 1.5l3.75 3.75m0 0l-3.75 3.75"/>
                    </svg>
                @break
            @endswitch

            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach

    <button type="button"
            x-data
            x-on:click="$dispatch('mvs-open-nav')"
            title="Más opciones"
            class="flex min-h-[56px] flex-col items-center justify-center gap-1 text-[11px] font-medium text-slate-500 transition hover:text-slate-700">

        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
        </svg>

        <span>Más</span>
    </button>
</nav>
