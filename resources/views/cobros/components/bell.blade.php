@php
    /*
     * BLOQUE 8 — campana CobrOS en el shell del dueño.
     * Reutiliza el sistema Core de notificaciones (alerts + alert_recipients con
     * read_at/dismissed_at): solo tipos de módulo CobrOS de la empresa activa,
     * del usuario autenticado. Sin Web Push. Badge oculto cuando no hay pendientes.
     */
    use App\Models\Alert;
    use App\Models\AlertRecipient;
    use App\Services\Notifications\AlertTypeRegistry;

    $bellUser = auth()->user();
    $bellIsOwner = (bool) ($bellUser && $bellUser->hasPermission('cobros.portal_dueno', $company));
    $bellVisible = (bool) ($bellUser
        && $bellUser->hasPermission('cobros.acceder', $company)
        && ($bellIsOwner || $bellUser->hasPermission('cobros.cobrar', $company)));
    $bellRows = collect();
    $bellCount = 0;
    // Home según rol: dueño → solicitudes del dueño; cobrador → Mis solicitudes.
    // Evita el 403 al abrir el enlace del pie de la campana desde el rol cobrador.
    $bellHome = $bellIsOwner
        ? route('cobros.owner.solicitudes.index')
        : route('cobros.collector.credit-requests.index');

    if ($bellVisible) {
        $cobrosTypes = array_keys(array_filter(AlertTypeRegistry::all(), fn (array $definition) => ($definition['module'] ?? '') === 'CobrOS'));

        $pending = AlertRecipient::query()
            ->where('user_id', $bellUser->id)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->whereHas('alert', function ($query) use ($company, $cobrosTypes) {
                $query->where('company_id', $company->id)
                    ->whereIn('type', $cobrosTypes)
                    ->where('status', '!=', Alert::STATUS_RESOLVED);
            })
            ->with('alert');

        $bellCount = (clone $pending)->count();
        $bellRows = $pending->get()
            ->sortByDesc(fn (AlertRecipient $row) => optional($row->alert)->occurred_at)
            ->take(5)
            ->values();
    }
@endphp
@if($bellVisible)
<div class="relative" id="cobros-bell">
    <details class="relative">
        <summary class="flex min-h-[44px] min-w-[44px] cursor-pointer items-center justify-center rounded-xl border border-slate-300 bg-white px-3 text-base" aria-label="Notificaciones">
            <span aria-hidden="true">🔔</span>
            @if($bellCount > 0)
                <span class="absolute -right-1 -top-1 flex h-5 min-w-[20px] items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-bold text-white ring-2 ring-white shadow-sm" data-bell-badge>{{ $bellCount }}</span>
            @endif
        </summary>
        <div class="absolute right-0 z-40 mt-2 w-[min(92vw,22rem)] rounded-2xl border border-slate-200 bg-white p-3 shadow-lg">
            <p class="mb-2 text-sm font-bold text-slate-800">Notificaciones CobrOS</p>
            @if($bellCount === 0)
                <p class="text-sm text-slate-500">Sin novedades pendientes.</p>
            @else
                <ul class="max-h-72 space-y-2 overflow-y-auto">
                    @foreach($bellRows as $row)
                        @php $alert = $row->alert; @endphp
                        @if($alert)
                            {{-- Click → endpoint open: marca read_at de ESTE recipient y redirige. --}}
                            <li>
                                <a href="{{ route('cobros.notifications.open', $row->id) }}"
                                   class="block rounded-xl border border-slate-100 bg-slate-50 p-3 text-sm hover:bg-amber-50">
                                    <span class="font-semibold text-slate-800">{{ $alert->metadata['bell_text'] ?? AlertTypeRegistry::label($alert->type) }}</span>
                                    <span class="mt-0.5 block text-xs text-slate-500">{{ optional($alert->occurred_at)->format('d/m/Y H:i') }}</span>
                                </a>
                            </li>
                        @endif
                    @endforeach
                </ul>
            @endif
            <a href="{{ $bellHome }}" class="mt-3 block min-h-[40px] rounded-xl bg-primary px-4 py-2 text-center text-sm font-bold text-white hover:bg-primary-hover">{{ $bellIsOwner ? 'Ver solicitudes' : 'Mis solicitudes' }}</a>
        </div>
    </details>
</div>
@endif
