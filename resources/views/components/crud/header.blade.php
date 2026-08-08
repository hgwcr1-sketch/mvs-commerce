<div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between mb-8">

    <div class="min-w-0">

        <h1 class="text-3xl font-bold tracking-tight text-slate-800">
            {{ $title }}
        </h1>

        @isset($description)
            <p class="mt-2 max-w-3xl text-sm text-slate-500">
                {{ $description }}
            </p>
        @endisset

    </div>

    @if(trim($actions ?? '') !== '')
        <div class="flex flex-wrap items-center gap-3">
            {{ $actions }}
        </div>
    @endif

</div>