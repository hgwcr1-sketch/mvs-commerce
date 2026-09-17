@php
    $headerCompany = \App\Models\Company::find(session('active_company_id'));
    $headerLogoPath = ltrim(str_replace('\\', '/', trim((string) ($headerCompany?->logo ?? ''))), '/');
    $headerLogoSegments = array_values(array_filter(explode('/', $headerLogoPath), fn ($segment) => $segment !== ''));
    $headerLogoPathIsSafe = $headerLogoSegments !== [] && ! in_array('..', $headerLogoSegments, true);
    $headerPublicLogoPath = $headerLogoPathIsSafe
        ? public_path('storage/'.$headerLogoPath)
        : null;
    $headerHasLogo = $headerLogoPath !== ''
        && $headerLogoPathIsSafe
        && \Illuminate\Support\Facades\Storage::disk('public')->exists($headerLogoPath)
        && is_file($headerPublicLogoPath);
    $headerLogoUrl = $headerHasLogo
        ? request()->getBaseUrl().'/storage/'.implode('/', array_map('rawurlencode', $headerLogoSegments))
        : null;
    $headerBranches = auth()->user()
        ->branches()
        ->where('branches.company_id', session('active_company_id'))
        ->where('branches.is_active', true)
        ->orderBy('branches.name')
        ->get();

    // La consulta administrativa no utiliza el selector operativo.
    $canConsolidate = auth()->user()->hasPermission('dashboard.admin', $headerCompany);
    $pendingReceptionCount = \Illuminate\Support\Facades\Schema::hasTable('purchase_verifications') && session('active_branch_id')
        ? \App\Models\PurchaseVerification::query()
            ->where('company_id', session('active_company_id'))
            ->where('branch_id', session('active_branch_id'))
            ->where('assigned_to', auth()->id())
            ->whereIn('status', \App\Models\PurchaseVerification::OPEN_STATUSES)
            ->count()
        : 0;
@endphp

<header
    class="sticky top-0 z-30 flex h-14 shrink-0 items-center justify-between gap-2 border-b border-slate-200 bg-white px-3 sm:px-4 md:h-16 md:px-6">

    {{-- IDENTIDAD --}}
    <div class="flex min-w-0 items-center gap-2.5">

        @if($headerHasLogo)
            <img
                src="{{ $headerLogoUrl }}"
                alt="Logo de {{ $headerCompany->trade_name }}"
                onerror="this.hidden=true; this.nextElementSibling.hidden=false"
                class="h-9 w-9 shrink-0 rounded-lg border border-slate-200 bg-white object-contain">
            <span
                hidden
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-200 text-sm font-bold text-slate-700"
                aria-label="Empresa {{ $headerCompany->trade_name }}">
                {{ mb_strtoupper(mb_substr(trim($headerCompany->trade_name), 0, 1)) }}
            </span>
        @else
            <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-200 text-sm font-bold text-slate-700"
                aria-label="Empresa {{ $headerCompany?->trade_name ?? 'sin seleccionar' }}">
                {{ mb_strtoupper(mb_substr(trim($headerCompany?->trade_name ?? 'E'), 0, 1)) }}
            </span>
        @endif

        <div class="min-w-0 leading-tight">

            <p class="truncate text-sm font-bold text-slate-800">
                {{ $headerCompany->trade_name ?? 'MVS Commerce' }}
            </p>

            <p class="hidden truncate text-xs text-slate-500 md:block">
                @yield('title', 'Dashboard')
            </p>

        </div>

    </div>

    {{-- ACCIONES --}}
    <div class="flex shrink-0 items-center gap-2">

        <span class="hidden border-r border-slate-200 pr-3 text-xs font-semibold text-slate-500 lg:inline">
            MVS Commerce
        </span>

        @can('compras.recepcion.verificar')
            <a href="{{ route('purchase-verifications.index') }}" class="relative flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 hover:bg-slate-100" title="Verificaciones de mercadería" aria-label="Verificaciones pendientes: {{ $pendingReceptionCount }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 01-6 0"/></svg>
                @if($pendingReceptionCount)<span class="absolute right-0.5 top-0.5 min-w-5 rounded-full bg-red-600 px-1 text-center text-xs font-bold leading-5 text-white">{{ $pendingReceptionCount > 99 ? '99+' : $pendingReceptionCount }}</span>@endif
            </a>
        @endcan

        @can('notificaciones.ver')
            <div class="relative" x-data="notificationBell()" @click.away="open = false">
                <button
                    type="button"
                    @click="open = !open; if(open) loadRecent()"
                    class="relative flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 hover:bg-slate-100"
                    aria-label="Notificaciones"
                    title="Centro de notificaciones">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4a2 2 0 01-.6-1.4V11a6 2 0 00-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 01-6 0"/></svg>
                    <span x-show="unreadCount > 0" x-cloak class="absolute right-0.5 top-0.5 min-w-5 rounded-full bg-primary px-1 text-center text-xs font-bold leading-5 text-slate-900" x-text="unreadCount > 99 ? '99+' : unreadCount"></span>
                </button>

                <div x-show="open" x-cloak x-transition class="absolute right-0 top-full z-50 mt-2 w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl sm:w-96">
                    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                        <h3 class="text-sm font-bold text-slate-800">Notificaciones</h3>
                        <a href="{{ route('notifications.index') }}" class="text-xs font-semibold text-primary hover:text-primary-hover">Ver todas</a>
                    </div>
                    <div class="max-h-80 overflow-y-auto">
                        <template x-if="alerts.length === 0">
                            <div class="px-4 py-6 text-center text-sm text-slate-500">No tiene notificaciones.</div>
                        </template>
                        <template x-for="alert in alerts" :key="alert.id">
                            <a :href="alert.link || '{{ route('notifications.index') }}'" @click.prevent="markRead(alert.id); navigate(alert.link)" class="block border-b border-slate-50 px-4 py-3 hover:bg-slate-50" :class="{ 'bg-primary/10': !alert.read_at }">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-xs font-bold uppercase tracking-wide" :class="severityColor(alert.severity)" x-text="alert.type_label"></p>
                                    <span class="shrink-0 text-xs text-slate-400" x-text="alert.occurred_at_short"></span>
                                </div>
                                <p class="mt-1 text-sm text-slate-700 line-clamp-2" x-text="alert.message"></p>
                            </a>
                        </template>
                    </div>
                    <div class="border-t border-slate-100 px-4 py-2 text-center">
                        <button type="button" @click="markRead()" class="text-xs font-semibold text-slate-600 hover:text-slate-800">Marcar todas como leídas</button>
                    </div>
                </div>
            </div>
        @endcan

        @if($headerBranches->isNotEmpty() && (!$canConsolidate || !request()->routeIs('dashboard')))

            <form method="POST" action="{{ route('branch.active.update') }}">

                @csrf

                <label class="sr-only" for="header-branch">Sucursal activa</label>

                <select
                    id="header-branch"
                    name="branch_id"
                    onchange="this.form.submit()"
                    class="min-h-11 max-w-[8.5rem] rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm font-semibold text-slate-700 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/40 sm:max-w-[12rem]">

                    <option value="" disabled @selected(!session('active_branch_id'))>
                        Sucursal
                    </option>

                    @foreach($headerBranches as $branch)

                        <option value="{{ $branch->id }}" @selected(session('active_branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>

                    @endforeach

                </select>

            </form>

        @endif

        <form method="POST" action="{{ route('logout') }}" class="hidden md:block">

            @csrf

            <button
                type="submit"
                title="Cerrar sesión"
                aria-label="Cerrar sesión"
                class="flex h-10 w-10 items-center justify-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">

                <svg xmlns="http://www.w3.org/2000/svg"
                    class="h-5 w-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2">

                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1"/>

                </svg>

            </button>

        </form>

        <div
            class="flex h-9 w-9 items-center justify-center rounded-full bg-amber-500 text-sm font-bold text-slate-900 md:h-10 md:w-10"
            title="{{ auth()->user()->name }}">

            {{ mb_strtoupper(mb_substr(trim(auth()->user()->name), 0, 1)) }}

        </div>

    </div>

</header>

@can('notificaciones.ver')
<script>
function notificationBell() {
    return {
        open: false,
        unreadCount: 0,
        alerts: [],
        init() {
            this.loadCount();
        },
        loadCount() {
            fetch('{{ route('notifications.unread-count') }}', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => this.unreadCount = data.count || 0)
            .catch(() => {});
        },
        loadRecent() {
            fetch('{{ route('notifications.recent') }}', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                this.alerts = (data.alerts || []).map(a => ({
                    ...a,
                    occurred_at_short: a.occurred_at ? new Date(a.occurred_at).toLocaleString('es-CR', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' }) : ''
                }));
                this.loadCount();
            })
            .catch(() => {});
        },
        markRead(alertId) {
            fetch('{{ route('notifications.mark-read') }}', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ alert_id: alertId })
            })
            .then(() => {
                if (alertId) {
                    const alert = this.alerts.find(a => a.id === alertId);
                    if (alert) alert.read_at = new Date().toISOString();
                } else {
                    this.alerts.forEach(a => a.read_at = new Date().toISOString());
                }
                this.loadCount();
            })
            .catch(() => {});
        },
        navigate(link) {
            if (link) window.location.href = link;
            else window.location.href = '{{ route('notifications.index') }}';
        },
        severityColor(severity) {
            return {
                'CRITICA': 'text-red-600',
                'ATENCION': 'text-amber-600',
                'INFORMATIVA': 'text-blue-600'
            }[severity] || 'text-slate-500';
        }
    };
}
</script>
@endcan
