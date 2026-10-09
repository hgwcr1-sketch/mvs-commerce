@php
    $doneUntil = $step - 1;
@endphp
<nav aria-label="Progreso del registro" class="mx-auto max-w-5xl px-4 sm:px-6">
    <ol class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ($progressSteps as $number => $label)
            @php
                $isDone = $number <= $doneUntil && $number <= 3;
                $isActive = $number === $step;
                $isPending = $number === 4;
            @endphp
            <li @if($isActive) aria-current="step" @endif class="flex min-h-11 items-center gap-3 rounded-xl px-3 py-2 @if($isDone) bg-primary text-black font-bold @elseif($isActive) border border-primary text-primary font-bold @else border border-white text-slate-400 @endif">
                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full @if($isDone) bg-black text-primary @elseif($isActive) bg-primary text-black @else border border-white text-slate-400 @endif text-xs font-bold">{{ $number }}</span>
                <span class="text-sm">{{ $label }}</span>
                @if($isPending)
                    <span class="ml-auto text-xs font-bold uppercase text-slate-400">Próximamente</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
