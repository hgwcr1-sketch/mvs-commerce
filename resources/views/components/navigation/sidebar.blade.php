<aside id="sidebar"
class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col transition-all duration-300">

    {{-- LOGO --}}
<div class="flex flex-col items-center py-4 border-b border-slate-800 shrink-0">

    <img
    src="{{ asset('images/logo-mvs-corto.png') }}"
    alt="MVS Commerce"
    class="w-16 h-16 object-contain">

    <h2 class="mt-3 text-lg font-bold text-white">
        MVS Commerce
    </h2>

    <p class="text-xs text-slate-400">
        ERP Profesional
    </p>

</div>

    {{-- MENÚ --}}

<nav class="flex flex-col gap-2 w-full py-4 px-3 flex-1 min-h-0 overflow-y-auto">


   {{-- DASHBOARD --}}

@php
    $company = \App\Models\Company::find(session('active_company_id'));
@endphp

@if(auth()->user()->hasPermission('dashboard.admin', $company))

<x-navigation.item
    route="dashboard"
    icon="home"
    label="Dashboard"
    :active="request()->routeIs('dashboard')" />

@elseif(auth()->user()->hasPermission('dashboard.ver', $company))

<x-navigation.item
    route="dashboard"
    icon="home"
    label="Mi Dashboard"
    :active="request()->routeIs('dashboard')" />

@endif

    {{-- USUARIOS --}}
    @can('usuarios.ver')

        <x-navigation.item
            route="usuarios.index"
            icon="users"
            label="Usuarios"
            :active="request()->routeIs('usuarios.*')" />

    @endcan



        {{-- PRODUCTOS --}}
    @canany([
        'productos.ver',
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

{{-- INVENTARIO --}}
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
    {{-- CLIENTES --}}
    @can('clientes.ver')

        <x-navigation.item
            route="clientes.index"
            icon="users"
            label="Clientes"
            :active="request()->routeIs('clientes.*')" />

    @endcan

        {{-- PROVEEDORES --}}
    @can('proveedores.ver')

        <x-navigation.item
            route="proveedores.index"
            icon="users"
            label="Proveedores"
            :active="request()->routeIs('proveedores.*')" />

    @endcan


    {{-- ADMINISTRACIÓN --}}
    @canany(['usuarios.ver', 'roles.ver'])

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

    @endcanany


</nav>

    {{-- FOOTER --}}

    <a href="#" class="mb-2 flex items-center gap-2 pl-4 text-xs text-slate-400 hover:text-white transition">

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

    <span>Configuración</span>

</a>
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

    <span>Ayuda</span>

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

        <span>Cerrar sesión</span>

    </button>
</form>

    <div class="border-t border-slate-800 py-4 text-center">

        <div class="text-[10px] text-slate-400">
            Versión 1.0
        </div>

        <div class="mt-1 text-xs font-medium text-slate-400">
    ● En línea
</div>

    </div>

</aside>