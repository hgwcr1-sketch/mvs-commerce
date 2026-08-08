<header
    class="sticky top-0 z-40 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-6">

    {{-- IZQUIERDA --}}
    <div class="flex items-center gap-5">

        <button
            id="sidebar-toggle"
            class="flex h-10 w-10 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-100">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-7 w-7"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M4 6h16M4 12h16M4 18h16"/>

            </svg>

        </button>

        <div>

            <h1 class="text-xl font-bold tracking-tight text-slate-800">
                MYM BEAUTY CENTER
            </h1>

            <p class="text-sm text-slate-500">
                @yield('title','Dashboard')
            </p>

        </div>

    </div>

    {{-- BUSCADOR --}}
    <div class="flex flex-1 justify-center px-10">

        <div class="relative w-full max-w-xl">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="absolute left-4 top-1/2 -mt-px h-5 w-5 -translate-y-1/2 text-slate-400"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="m21 21-4.35-4.35m1.85-5.15a7 7 0 1 1-14 0a7 7 0 0 1 14 0"/>

            </svg>

           <input
    type="text"
    placeholder="         Buscar productos, clientes, facturas..."
    class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-4 pr-4 text-sm transition">
        </div>

    </div>

    {{-- DERECHA --}}
    <div class="flex items-center gap-4">

    @php
    $headerBranches = auth()->user()
        ->branches()
        ->where('branches.company_id', session('active_company_id'))
        ->where('branches.is_active', true)
        ->orderBy('branches.name')
        ->get();
@endphp

@if($headerBranches->isNotEmpty())

    <form method="POST"
          action="{{ route('branch.active.update') }}">

        @csrf

        <select
            name="branch_id"
            onchange="this.form.submit()"
            class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500">

            <option value="" disabled
                @selected(!session('active_branch_id'))>
                Seleccionar sucursal
            </option>

            @foreach($headerBranches as $branch)

                <option
                    value="{{ $branch->id }}"
                    @selected(session('active_branch_id') == $branch->id)>

                    {{ $branch->name }}

                </option>

            @endforeach

        </select>

    </form>

@endif

        <button
            class="relative flex h-10 w-10 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-100">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>

            </svg>

            <span class="absolute right-2 top-2 h-2.5 w-2.5 rounded-full bg-red-500 border-2 border-white"></span>

        </button>

        <button
            class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 transition hover:bg-slate-50">

            <div
                class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-500 font-bold text-slate-900">

                A

            </div>

            <div>

                <div class="text-sm font-semibold text-slate-800">
                    Administrador
                </div>

                <div class="text-xs text-slate-500">
                    Mi cuenta
                </div>

            </div>

        </button>

    </div>

</header>