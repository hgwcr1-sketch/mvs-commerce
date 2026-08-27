@php
    $navContext = $context ?? 'shell';
@endphp

@if($navContext === 'shell')
<aside id="app-sidebar"
    x-data="sidebarShell"
    x-on:focusin="hovered = true"
    x-on:focusout="if (! $event.relatedTarget || ! $el.contains($event.relatedTarget)) hovered = false"
    class="hidden w-[68px] shrink-0 flex-col overflow-x-hidden border-r border-slate-800 bg-slate-900 transition-[width] duration-200 ease-out md:flex"
    x-bind:class="[isExpanded ? 'lg:w-64' : 'lg:w-[68px]', isExpanded ? '' : 'collapsed']">
@else
<aside class="flex w-full flex-col bg-slate-900" aria-label="Navegación">
@endif

    {{-- LOGO --}}
<div class="relative flex flex-col items-center py-4 border-b border-slate-800 shrink-0">

    <img
    src="{{ asset('images/logo-mvs-corto.png') }}"
    alt="MVS Commerce"
    class="h-16 w-16 object-contain">

    <h2 class="nav-fade mt-3 text-lg font-bold text-white">
        MVS Commerce
    </h2>

    <p class="nav-fade text-xs text-slate-400">
        ERP Profesional
    </p>

    @if($navContext === 'shell')
    <button
        type="button"
        x-on:click="togglePinned()"
        x-bind:title="isExpanded ? 'Desanclar menú' : 'Fijar menú expandido'"
        x-bind:aria-pressed="pinned ? 'true' : 'false'"
        aria-label="Fijar o desanclar el menú expandido"
        class="absolute right-1 top-1 hidden h-7 w-7 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-800 hover:text-white lg:flex">

        <svg xmlns="http://www.w3.org/2000/svg"
            class="h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
            x-bind:class="pinned ? '' : 'rotate-180'">

            <path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>

        </svg>

    </button>
    @endif

</div>

    {{-- MENÚ --}}

<nav class="flex flex-col gap-2 w-full py-4 px-3 flex-1 min-h-0 overflow-y-auto">


   {{-- DASHBOARD --}}

@php
    $company = \App\Models\Company::find(session('active_company_id'));
@endphp

@if($company && auth()->user()->hasPermission('dashboard.admin', $company))

<x-navigation.item
    route="dashboard"
    icon="home"
    label="Dashboard"
    :active="request()->routeIs('dashboard')" />

@elseif($company && auth()->user()->hasPermission('dashboard.ver', $company))

<x-navigation.item
    route="dashboard"
    icon="home"
    label="Mi Dashboard"
    :active="request()->routeIs('dashboard')" />

@endif

    {{-- VENTAS --}}
    <div class="nav-desktop-group">
    @canany(['pos.acceder', 'ventas.ver', 'cotizaciones.ver', 'pedidos.ver', 'cuentas_cobrar.ver', 'cuentas_pagar.ver', 'apartados.ver', 'devoluciones.ver'])
        <x-navigation.dropdown
            icon="tag"
            label="Ventas"
            :active="request()->routeIs('pos.*', 'ventas.*', 'cotizaciones.*', 'pedidos.*', 'cuentas-por-cobrar.*', 'cuentas-por-pagar.*', 'apartados.*')">

            @can('pos.acceder')
                <x-navigation.submenu route="pos.index" label="POS" />
            @endcan

            @can('ventas.ver')
                <x-navigation.submenu route="ventas.index" label="Ventas" :active="request()->routeIs('ventas.*') && !request()->boolean('with_returns')" />
            @endcan

            @can('cotizaciones.ver')
                <x-navigation.submenu route="cotizaciones.index" label="Cotizaciones" />
            @endcan

            @can('pedidos.ver')
                <x-navigation.submenu route="pedidos.index" label="Pedidos" />
            @endcan

            @can('cuentas_cobrar.ver')
                <x-navigation.submenu route="cuentas-por-cobrar.index" label="Cuentas por cobrar" />
            @endcan

            @can('cuentas_pagar.ver')
                <x-navigation.submenu route="cuentas-por-pagar.index" label="Cuentas por pagar" />
            @endcan

            @can('apartados.ver')
                <x-navigation.submenu route="apartados.index" label="Apartados" />
            @endcan

            @can('devoluciones.ver')
                <x-navigation.submenu route="ventas.index" label="Devoluciones" :parameters="['with_returns' => 1]" :active="request()->routeIs('ventas.index') && request()->boolean('with_returns')" />
            @endcan

        </x-navigation.dropdown>
    @endcanany
    </div>

    @canany(['caja.abrir', 'caja.ver'])
        <x-navigation.item route="cash.index" icon="tag" label="Caja" :active="request()->routeIs('cash.*')" />
    @endcanany

        {{-- PRODUCTOS --}}
    <div class="nav-desktop-group">
    @canany([
        'productos.ver',
        'productos.etiquetas.imprimir',
        'categorias.ver',
        'marcas.ver',
        'unidades.ver'
    ])

        <x-navigation.dropdown
            icon="cube"
            label="Productos">

            @can('productos.ver')
                <x-navigation.submenu
                    route="productos.index"
                    label="Listado de Productos" />
            @endcan

            @can('productos.etiquetas.imprimir')
                <x-navigation.submenu route="labels.index" label="Centro de Etiquetas" />
            @endcan

            @can('categorias.ver')
                <x-navigation.submenu
                    route="categorias.index"
                    label="Categorías" />
            @endcan

            @can('marcas.ver')
                <x-navigation.submenu
                    route="marcas.index"
                    label="Marcas" />
            @endcan

            @can('unidades.ver')
                <x-navigation.submenu
                    route="unidades.index"
                    label="Unidades de Medida" />
            @endcan

        </x-navigation.dropdown>

    @endcanany
    </div>

{{-- INVENTARIO --}}
<div class="nav-desktop-group">
@canany([
    'inventario.ver',
    'inventario.ajustar',
    'inventario.kardex',
    'inventario.transferir'
])

    <x-navigation.dropdown
        icon="cube"
        label="Inventario">

        @can('inventario.ver')
            <x-navigation.submenu
                route="inventario.index"
                label="Existencias" />
        @endcan

        @can('inventario.ajustar')
            <x-navigation.submenu
                route="ajustes-inventario.create"
                label="Ajustes de Inventario" />
        @endcan

        @can('inventario.ver')
    <x-navigation.submenu
        route="importaciones.inventario"
        label="Importar Inventario" />
        @endcan

        @can('inventario.kardex')
            <x-navigation.submenu
                route="kardex.index"
                label="Kardex" />
        @endcan

        @can('inventario.transferir')
            <x-navigation.submenu
                route="transferencias.index"
                label="Transferencias" />
        @endcan

    </x-navigation.dropdown>

@endcanany
</div>
    {{-- CLIENTES --}}
    @can('clientes.ver')

        <x-navigation.item
            route="clientes.index"
            icon="users"
            label="Clientes"
            :active="request()->routeIs('clientes.*')" />

    @endcan

    {{-- FIDELIZACIÓN --}}
    <div class="nav-desktop-group">
    @canany(['fidelidad.dashboard', 'fidelidad.oportunidades', 'fidelidad.clientes', 'fidelidad.ver', 'fidelidad.configuracion', 'fidelidad.ajustes', 'fidelidad.multiplicadores', 'fidelidad.premios', 'fidelidad.canjes', 'fidelidad.portal', 'fidelidad.promociones', 'fidelidad.portal.ver', 'fidelidad.portal.configurar', 'fidelidad.portal.contenido', 'fidelidad.portal.enlaces'])
        <x-navigation.dropdown icon="users" label="Fidelización" :active="request()->routeIs('loyalty.*')">
            @can('fidelidad.dashboard')
                <x-navigation.submenu route="loyalty.dashboard" label="Dashboard" />
            @endcan
            @can('fidelidad.oportunidades')
                <x-navigation.submenu route="loyalty.opportunities.index" label="Oportunidades" />
            @endcan
            @can('fidelidad.ver')
                <x-navigation.submenu route="loyalty.kardex.index" label="Kardex" />
            @endcan
            @can('fidelidad.configuracion')
                <x-navigation.submenu route="loyalty.rules.index" label="Centro de reglas" />
            @endcan
            @can('fidelidad.multiplicadores')
                <x-navigation.submenu route="loyalty.multipliers.index" label="Multiplicadores" />
            @endcan
            @can('fidelidad.premios')
                <x-navigation.submenu route="loyalty.rewards.index" label="Premios" />
            @endcan
            @can('fidelidad.canjes')
                <x-navigation.submenu route="loyalty.redemptions.index" label="Canjes de premios" />
            @endcan
            @can('fidelidad.ajustes')
                <x-navigation.submenu route="loyalty.adjustments.index" label="Ajustes de puntos" />
            @endcan
            @canany(['fidelidad.portal.ver', 'fidelidad.portal.configurar', 'fidelidad.portal.contenido', 'fidelidad.portal.enlaces', 'fidelidad.portal', 'fidelidad.promociones'])
                <x-navigation.submenu route="loyalty.portal-management.index" label="Portal de Clientes" />
            @endcanany
            @can('configuracion.editar')
                <x-navigation.submenu route="configuracion.index" label="Configuración" />
            @endcan
        </x-navigation.dropdown>
    @endcanany
    </div>

        {{-- PROVEEDORES --}}
    @can('proveedores.ver')

        <x-navigation.item
            route="proveedores.index"
            icon="users"
            label="Proveedores"
            :active="request()->routeIs('proveedores.*')" />

    @endcan

    {{-- COMPRAS --}}
    @can('compras.ver')

        <x-navigation.item
            route="compras.index"
            icon="tag"
            label="Compras"
            :active="request()->routeIs('compras.*')" />

    @endcan

    @can('compras.ordenes')
        <x-navigation.item route="ordenes-compra.index" icon="tag" label="Pedidos a proveedor" :active="request()->routeIs('ordenes-compra.*')" />
    @endcan

    @canany(['compras.crear', 'clientes.crear', 'inventario.ver', 'reportes.exportar', 'reportes.ver'])
        <x-navigation.item
            route="data-center.index"
            icon="cube"
            label="Centro de Datos"
            :active="request()->routeIs('data-center.*', 'importaciones.*')" />
    @endcanany


    {{-- ADMINISTRACIÓN --}}
    @canany(['usuarios.ver', 'roles.ver'])

        <div class="nav-desktop-group">
        <x-navigation.dropdown
            icon="settings"
            label="Administración">


            @can('usuarios.ver')

                <x-navigation.submenu
                    route="usuarios.index"
                    label="Usuarios" />

            @endcan



            @can('roles.ver')

                <x-navigation.submenu
                    route="roles.index"
                    label="Roles y Permisos" />

            @endcan


        </x-navigation.dropdown>

        </div>
    @endcanany

    {{-- MÁS (móvil/tablet): abre el sheet con el menú completo --}}
    <button
        type="button"
        class="nav-more-trigger hidden items-center gap-4 rounded-xl px-4 py-3 text-slate-300 transition hover:bg-slate-800 hover:text-white"
        x-on:click="$dispatch('mvs-open-nav')"
        aria-label="Abrir menú completo">

        <div class="flex h-6 w-6 items-center justify-center shrink-0">

            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h.01M12 12h.01M19 12h.01"/>
            </svg>

        </div>

        <span class="nav-fade text-sm font-medium whitespace-nowrap">
            Más
        </span>

    </button>

</nav>

    {{-- FOOTER --}}

@canany(['configuracion.ver', 'formas_pago.administrar', 'caja.administrar'])
<div x-data="{ open: false }" class="mb-2 px-3">
    <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-2 text-xs text-slate-400 transition hover:text-white {{ request()->routeIs('settings.pos.payment-methods.*', 'settings.cash-registers.*') ? 'text-amber-400' : '' }}">
        <span class="flex items-center gap-2">

    <svg xmlns="http://www.w3.org/2000/svg"
        class="h-3.5 w-3.5"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="2">

        <path stroke-linecap="round"
            stroke-linejoin="round"
            d="M10.325 4.317a1 1 0 011.35-.936l1.623.541a1 1 0 00.632 0l1.623-.54a1 1 0 011.35.935l.093 1.71a1 1 0 00.29.64l1.21 1.21a1 1 0 010 1.414l-1.21 1.21a1 1 0 00-.29.64l-.093 1.71a1 1 0 01-1.35.936l-1.623-.541a1 1 0 00-.632 0l-1.623.54a1 1 0 01-1.35-.935l-.093-1.71a1 1 0 00-.29-.64l-1.21-1.21a1 1 0 010-1.414l1.21-1.21a1 1 0 00.29-.64l.093-1.71z"/>

    </svg>

            <span class="nav-fade">Configuración</span>
        </span>
        <span aria-hidden="true" :class="open ? 'rotate-180' : ''" class="nav-fade transition">▼</span>
    </button>
    <div x-cloak x-show="open" x-transition class="nav-sub mt-2 space-y-1 pl-5">
        @can('configuracion.ver')
            <a href="{{ route('configuracion.index') }}" class="block py-1 text-xs {{ request()->routeIs('configuracion.*') ? 'font-semibold text-amber-400' : 'text-slate-400 hover:text-white' }}">Configuración general</a>
            <a href="{{ route('branches.index') }}" class="block py-1 text-xs {{ request()->routeIs('branches.*') ? 'font-semibold text-amber-400' : 'text-slate-400 hover:text-white' }}">Sucursales</a>
        @endcan
        @can('caja.administrar')
            <a href="{{ route('settings.cash.edit') }}" class="block py-1 text-xs {{ request()->routeIs('settings.cash.edit', 'settings.cash.update') ? 'font-semibold text-amber-400' : 'text-slate-400 hover:text-white' }}">Configuración de Caja</a>
            <a href="{{ route('settings.cash-registers.index') }}" class="block py-1 text-xs {{ request()->routeIs('settings.cash-registers.*') ? 'font-semibold text-amber-400' : 'text-slate-400 hover:text-white' }}">Cajas</a>
        @endcan
        @can('formas_pago.administrar')
            <a href="{{ route('settings.pos.payment-methods.index') }}" class="block py-1 text-xs {{ request()->routeIs('settings.pos.payment-methods.*') ? 'font-semibold text-amber-400' : 'text-slate-400 hover:text-white' }}">Formas de pago</a>
        @endcan
    </div>
</div>
@endcanany
<a href="#" class="mb-3 flex items-center gap-2 pl-4 text-xs text-slate-400 hover:text-white transition">

    <svg xmlns="http://www.w3.org/2000/svg"
        class="h-3.5 w-3.5"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="2">

        <path stroke-linecap="round"
            stroke-linejoin="round"
            d="M9.09 9a3 3 0 115.82 1c0 2-3 3-3 3"/>

        <path stroke-linecap="round"
            stroke-linejoin="round"
            d="M12 17h.01"/>

    </svg>

            <span class="nav-fade">Ayuda</span>

</a>

<form method="POST" action="{{ route('logout') }}" class="mb-3">
    @csrf

    <button
        type="submit"
        class="w-full flex items-center gap-2 pl-4 text-xs text-slate-400 hover:text-white transition">

        <svg xmlns="http://www.w3.org/2000/svg"
            class="h-3.5 w-3.5"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2">

            <path stroke-linecap="round"
                stroke-linejoin="round"
                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1"/>

        </svg>

        <span class="nav-fade">Cerrar sesión</span>

    </button>
</form>

    <div class="nav-fade border-t border-slate-800 py-4 text-center">

        <div class="text-[10px] text-slate-400">
            Versión 1.0
        </div>

        <div class="mt-1 text-xs font-medium text-slate-400">
    ● En línea
</div>

    </div>

</aside>
