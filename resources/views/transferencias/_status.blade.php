@php
    $statusColor = match ($transfer->status) {
        'received', 'completed' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        'received_with_differences' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'in_transit', 'in_review' => 'bg-blue-50 text-blue-800 ring-blue-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
@endphp
<span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $statusColor }}">{{ $statusLabels[$transfer->status] ?? 'Estado no reconocido' }}</span>
