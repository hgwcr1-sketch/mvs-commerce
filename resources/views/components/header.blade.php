@php
    $headerCompany = \App\Models\Company::find(session('active_company_id'));
    $headerBranches = auth()->user()
        ->branches()
        ->where('branches.company_id', session('active_company_id'))
        ->where('branches.is_active', true)
        ->orderBy('branches.name')
        ->get();
@endphp

<header
    class="sticky top-0 z-30 flex h-14 shrink-0 items-center justify-between gap-2 border-b border-slate-200 bg-white px-3 sm:px-4 md:h-16 md:px-6">

    {{-- IDENTIDAD --}}
    <div class="flex min-w-0 items-center gap-2.5">

        <img
            src="{{ asset('images/logo-mvs-corto.png') }}"
            alt="MVS Commerce"
            class="h-8 w-8 shrink-0 rounded-lg object-contain">

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

        @if($headerBranches->isNotEmpty())

            <form method="POST" action="{{ route('branch.active.update') }}">

                @csrf

                <label class="sr-only" for="header-branch">Sucursal activa</label>

                <select
                    id="header-branch"
                    name="branch_id"
                    onchange="this.form.submit()"
                    class="max-w-[8.5rem] rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm font-semibold text-slate-700 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/40 sm:max-w-[12rem]">

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
