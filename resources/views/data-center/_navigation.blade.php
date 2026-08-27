<nav aria-label="Secciones del Centro de Datos" class="grid grid-cols-1 gap-2 sm:grid-cols-3">
    @canany(['compras.crear', 'clientes.crear', 'inventario.ver'])
        <a href="{{ route('data-center.imports') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border px-4 py-3 text-sm font-semibold transition {{ request()->routeIs('data-center.imports') ? 'border-amber-500 bg-amber-50 text-amber-900' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300' }}">Importar</a>
    @endcanany
    @can('reportes.exportar')
        <a href="{{ route('data-center.exports') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border px-4 py-3 text-sm font-semibold transition {{ request()->routeIs('data-center.exports') ? 'border-amber-500 bg-amber-50 text-amber-900' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300' }}">Exportar</a>
    @endcan
    @can('reportes.ver')
        <a href="{{ route('data-center.reports') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border px-4 py-3 text-sm font-semibold transition {{ request()->routeIs('data-center.reports') ? 'border-amber-500 bg-amber-50 text-amber-900' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300' }}">Reportes</a>
    @endcan
</nav>
