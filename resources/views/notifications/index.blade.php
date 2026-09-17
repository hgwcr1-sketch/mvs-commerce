@extends('layouts.app')

@section('title', 'Centro de Notificaciones')

@section('content')
<div class="mx-auto max-w-5xl px-3 py-4 sm:px-4 sm:py-6">
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-bold text-slate-900 sm:text-2xl">Centro de Notificaciones</h1>
            <p class="text-sm text-slate-500">Alertas y mensajes relevantes para su empresa y sucursal.</p>
        </div>
        <div class="flex items-center gap-2">
            @if($counts['unread'] > 0)
                <form method="POST" action="{{ route('notifications.mark-read') }}" class="inline">
                    @csrf
                    <input type="hidden" name="mark_all" value="1">
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        Marcar todas como leídas
                    </button>
                </form>
            @endif
            @can('notificaciones.configurar')
                <a href="{{ route('notifications.preferences') }}" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-bold text-slate-900 hover:bg-primary-hover">
                    Configurar alertas
                </a>
            @endcan
        </div>
    </div>

    <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
        @php
            $tabs = [
                'all' => ['label' => 'Todas', 'count' => $counts['all']],
                'unread' => ['label' => 'No leídas', 'count' => $counts['unread']],
                'critical' => ['label' => 'Críticas', 'count' => $counts['critical']],
                'attention' => ['label' => 'Atención', 'count' => $counts['attention']],
                'info' => ['label' => 'Informativas', 'count' => $counts['info']],
            ];
        @endphp
        @foreach($tabs as $key => $tab)
            <a href="{{ route('notifications.index', ['filter' => $key]) }}"
               class="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold transition
                   {{ $filter === $key
                       ? 'bg-primary text-slate-900 shadow-sm'
                       : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">
                {{ $tab['label'] }}
                @if($tab['count'] > 0)
                    <span class="inline-flex min-h-5 items-center rounded-full px-1.5 text-xs font-bold
                        {{ $filter === $key ? 'bg-slate-900/10 text-slate-900' : 'bg-slate-100 text-slate-600' }}">
                        {{ $tab['count'] }}
                    </span>
                @endif
            </a>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        @forelse($alerts as $alert)
            @php
                $recipient = $alert->recipients->first();
                $isUnread = ! $recipient?->read_at;
                $dotClass = $isUnread ? 'bg-amber-500' : 'bg-slate-300';
                $severityClass = match($alert->severity) {
                    'CRITICA' => 'text-red-600',
                    'ATENCION' => 'text-amber-600',
                    default => 'text-blue-600',
                };
            @endphp
            <div class="flex items-start gap-3 border-b border-slate-100 px-4 py-4 last:border-b-0 hover:bg-slate-50 sm:gap-4 sm:px-6">
                <div class="mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $dotClass }}"></div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-bold uppercase tracking-wide {{ $severityClass }}">
                            {{ \App\Services\Notifications\AlertTypeRegistry::label($alert->type) }}
                        </span>
                        @if($alert->branch)
                            <span class="text-xs text-slate-400">{{ $alert->branch->name }}</span>
                        @endif
                        <span class="text-xs text-slate-400">{{ $alert->occurred_at?->diffForHumans() }}</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-800">{{ $alert->notes }}</p>
                    @if($alert->link)
                        <a href="{{ $alert->link }}" class="mt-2 inline-flex items-center text-sm font-semibold text-amber-600 hover:text-amber-700">
                            Ver detalle
                            <svg xmlns="http://www.w3.org/2000/svg" class="ml-1 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    @endif
                </div>
                @if($isUnread)
                    <form method="POST" action="{{ route('notifications.mark-read') }}" class="shrink-0">
                        @csrf
                        <input type="hidden" name="alert_id" value="{{ $alert->id }}">
                        <button type="submit" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 cursor-pointer" title="Marcar como leída">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </button>
                    </form>
                @endif
                <form method="POST" action="{{ route('notifications.dismiss', $alert) }}" class="shrink-0" onsubmit="return confirm('¿Descartar esta notificación?')">
                    @csrf
                    <button type="submit" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 cursor-pointer" title="Descartar">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </form>
            </div>
        @empty
            <div class="px-4 py-12 text-center text-slate-500">
                <p class="text-lg font-semibold">No tiene notificaciones</p>
                <p class="text-sm">Cuando ocurra un evento relevante aparecerá aquí.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $alerts->links() }}
    </div>
</div>
@endsection
