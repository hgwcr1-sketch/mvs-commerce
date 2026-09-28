@extends('layouts.app')
@section('title', 'Portal de Clientes')
@section('content')
<div class="space-y-6">
    <header><h1 class="text-2xl font-semibold text-slate-900">Portal de Clientes</h1><p class="mt-1 text-sm text-slate-500">Administra la experiencia del portal de esta empresa sin alterar el catálogo ni las reglas de puntos.</p></header>
    @if(session('success'))<div class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('success') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800"><p class="font-bold">Revise los datos ingresados:</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    @php
        $portalTabs = [
            ['id' => 'acceso-general', 'label' => 'Acceso general'],
            ['id' => 'resumen', 'label' => 'Resumen'],
        ];
    @endphp
    @if(auth()->user()->can('fidelidad.portal.contenido'))
        @php
            $portalTabs[] = ['id' => 'publicaciones', 'label' => 'Publicaciones'];
            $portalTabs[] = ['id' => 'destacados', 'label' => 'Productos destacados', 'badge' => $summary['posts']];
        @endphp
    @endif
    @if(auth()->user()->can('fidelidad.portal.enlaces'))
        @php $portalTabs[] = ['id' => 'enlaces', 'label' => 'Enlaces y botones', 'badge' => $summary['links']]; @endphp
    @endif
    @if(auth()->user()->can('fidelidad.portal.configurar'))
        @php $portalTabs[] = ['id' => 'configuracion', 'label' => 'Configuración']; @endphp
    @endif
    @php $portalTabs[] = ['id' => 'vista-previa', 'label' => 'Vista previa y accesos']; @endphp

    <x-tabs :tabs="$portalTabs" :active-tab="$errors->any() && auth()->user()->can('fidelidad.portal.contenido') ? 'publicaciones' : 'acceso-general'" variant="pills" aria-label="Secciones del Portal de Clientes">

    <section id="panel-acceso-general" role="tabpanel" aria-labelledby="tab-acceso-general" x-show="activeTab === 'acceso-general'" class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
        <h2 class="text-lg font-semibold">Acceso general al Portal</h2>
        <p class="mt-1 text-sm text-slate-500">URL única de esta empresa para login y autorregistro. Compártela con el cliente; el acceso es por empresa.</p>
        <div class="mt-4 grid gap-4 lg:grid-cols-[1.7fr_0.9fr]">
            <div class="space-y-3">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <p class="text-xs font-semibold uppercase text-slate-500">URL del Portal (login)</p>
                    <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="mt-1 block break-all text-sm font-semibold text-slate-900 underline">{{ $portalUrl }}</a>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="button" onclick="navigator.clipboard.writeText(@js($portalUrl)).then(()=>{this.textContent='¡Copiado!'; setTimeout(()=>this.textContent='Copiar URL',1500)}).catch(()=>prompt('Copia manualmente:', @js($portalUrl)))" class="min-h-11 flex-1 rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white">Copiar URL</button>
                    <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="min-h-11 flex flex-1 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold">Vista previa</a>
                </div>
                <p class="text-xs text-slate-500">Incluye enlace a “Registrarme / Crear mi cuenta”. La URL contiene el ID de la empresa para aislamiento.</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3 text-center">
                <p class="text-xs font-semibold uppercase text-slate-500">QR del Portal</p>
                <div id="portal-qr-brand-card" class="mt-3 rounded-xl border bg-white p-3" style="border-color:{{ $setting->brand_accent_color }}">
                <div class="flex items-center justify-center gap-2">
                    @if($setting->logoUrl($company))<img src="{{ $setting->logoUrl($company) }}" alt="Logo de {{ $setting->displayName($company) }}" class="h-10 w-10 rounded-lg border bg-white object-contain p-1">@endif
                    <span class="font-semibold" style="color:{{ $setting->brand_primary_color }}">{{ $setting->displayName($company) }}</span>
                </div>
                @if($portalQr)
                    <div class="mx-auto mt-3 w-[160px] max-w-full rounded-xl border border-slate-100 bg-white p-2 sm:w-[180px] lg:w-[200px] [&_svg]:h-auto [&_svg]:w-full [&_svg]:max-w-full">{!! $portalQr !!}</div>
                    <p class="mt-2 break-all text-xs text-slate-500">{{ $portalUrl }}</p>
                    </div>
                    <button type="button" onclick="const w=window.open('','_blank'); w.document.write('<html><head><title>QR Portal</title></head><body style=\'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:sans-serif\'><div style=\'max-width:360px;width:100%;padding:16px\'>'+document.getElementById('portal-qr-brand-card').outerHTML+'</div></body></html>'); w.document.close(); w.focus(); setTimeout(()=>w.print(), 250);" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold">Imprimir QR</button>
                @else
                    </div>
                    <p class="mt-2 text-sm text-slate-500">QR no disponible</p>
                @endif
            </div>
        </div>
    </section>

    <section id="panel-resumen" role="tabpanel" aria-labelledby="tab-resumen" x-show="activeTab === 'resumen'" x-cloak class="grid grid-cols-1 gap-3 sm:grid-cols-3"><div class="rounded-xl border bg-white p-5"><span class="text-sm text-slate-500">Publicaciones</span><strong class="mt-1 block text-2xl">{{ $summary['posts'] }}</strong></div><div class="rounded-xl border bg-white p-5"><span class="text-sm text-slate-500">Enlaces</span><strong class="mt-1 block text-2xl">{{ $summary['links'] }}</strong></div><div class="rounded-xl border bg-white p-5"><span class="text-sm text-slate-500">Accesos activos</span><strong class="mt-1 block text-2xl">{{ $summary['accesses'] }}</strong></div></section>

    @can('fidelidad.portal.contenido')
    <div id="panel-publicaciones" role="tabpanel" aria-labelledby="tab-publicaciones" x-show="activeTab === 'publicaciones'" x-cloak>
    <section id="publicaciones" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6"><h2 class="text-lg font-semibold text-slate-900">Nueva publicación</h2><p class="mt-1 text-sm text-slate-600">Cree contenido propio o asócielo con un producto existente.</p>
        <form method="POST" action="{{ route('loyalty.portal-management.posts.store') }}" enctype="multipart/form-data" class="mt-5 grid grid-cols-1 gap-5 md:grid-cols-2">@csrf
            @include('loyalty.portal-management._post-form-fields', ['post' => null])
            <button class="min-h-11 rounded-xl bg-amber-600 px-5 py-3 font-semibold text-white shadow-sm outline-none hover:bg-amber-700 focus:ring-4 focus:ring-amber-200 md:col-span-2 md:justify-self-end">Publicar</button>
        </form>
    </section>
    </div>

    <div id="panel-destacados" role="tabpanel" aria-labelledby="tab-destacados" x-show="activeTab === 'destacados'" x-cloak>
    <section id="destacados" class="space-y-4"><h2 class="text-lg font-semibold">Publicaciones y productos destacados</h2>@forelse($posts as $post)<article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"><h3 class="mb-4 text-base font-bold text-slate-900">Editar: {{ $post->title }}</h3><form method="POST" action="{{ route('loyalty.portal-management.posts.update', $post) }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-5 md:grid-cols-2">@csrf @method('PUT') @include('loyalty.portal-management._post-form-fields', ['post' => $post])<button class="min-h-11 rounded-xl bg-slate-900 px-5 py-3 font-semibold text-white outline-none hover:bg-slate-800 focus:ring-4 focus:ring-slate-300 md:col-span-2 md:justify-self-end">Guardar cambios</button></form><form method="POST" action="{{ route('loyalty.portal-management.posts.destroy', $post) }}" class="mt-3" onsubmit="return confirm('¿Eliminar publicación?')">@csrf @method('DELETE')<button class="min-h-11 rounded-xl px-4 text-sm font-semibold text-red-700 outline-none hover:bg-red-50 focus:ring-4 focus:ring-red-100">Eliminar</button></form></article>@empty<p class="rounded-xl border border-dashed bg-white p-6 text-center text-sm text-slate-500">No hay publicaciones.</p>@endforelse</section>
    </div>
    @endcan

    @can('fidelidad.portal.enlaces')
    <div id="panel-enlaces" role="tabpanel" aria-labelledby="tab-enlaces" x-show="activeTab === 'enlaces'" x-cloak>
    <section id="enlaces" class="rounded-2xl border bg-white p-4 sm:p-6"><h2 class="text-lg font-semibold">Enlaces y botones</h2><form method="POST" action="{{ route('loyalty.portal-management.links.store') }}" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4">@csrf<select name="type" class="min-h-11 rounded-xl border-slate-300">@foreach(['buy'=>'Comprar ahora','store'=>'Tienda/web','catalog'=>'Catálogo WhatsApp','whatsapp'=>'WhatsApp','other'=>'Otro'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select><input name="label" required maxlength="80" placeholder="Texto del botón" class="min-h-11 rounded-xl border-slate-300"><input type="url" name="url" required placeholder="https://..." class="min-h-11 rounded-xl border-slate-300"><button class="min-h-11 rounded-xl bg-amber-600 px-4 font-semibold text-white">Agregar</button><input type="hidden" name="is_active" value="1"></form><div class="mt-5 space-y-3">@foreach($links as $link)<form method="POST" action="{{ route('loyalty.portal-management.links.update', $link) }}" class="grid grid-cols-1 gap-2 md:grid-cols-5">@csrf @method('PUT')<input type="hidden" name="type" value="{{ $link->type }}"><input name="label" value="{{ $link->label }}" class="min-h-11 rounded-xl border-slate-300"><input type="url" name="url" value="{{ $link->url }}" class="min-h-11 rounded-xl border-slate-300 md:col-span-2"><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked($link->is_active)> Activo</label><button class="min-h-11 rounded-xl bg-slate-900 text-white">Guardar</button></form><form method="POST" action="{{ route('loyalty.portal-management.links.destroy', $link) }}">@csrf @method('DELETE')<button class="text-sm font-semibold text-red-700">Eliminar</button></form>@endforeach</div></section>
    </div>
    @endcan

    @can('fidelidad.portal.configurar')
    <div id="panel-configuracion" role="tabpanel" aria-labelledby="tab-configuracion" x-show="activeTab === 'configuracion'" x-cloak>
    <section id="configuracion" class="rounded-2xl border bg-white p-4 sm:p-6">
        <h2 class="text-lg font-semibold">Identidad y contenido del Portal</h2>
        <p class="mt-1 text-sm text-slate-500">Esta configuración pertenece únicamente a {{ $company->trade_name }}.</p>
        <form method="POST" action="{{ route('loyalty.portal-management.settings.update') }}" enctype="multipart/form-data" class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2">@csrf @method('PUT')
            <label class="text-sm font-semibold sm:col-span-2">Nombre comercial del Portal<input name="portal_name" maxlength="120" value="{{ old('portal_name', $setting->portal_name) }}" placeholder="{{ $company->trade_name }}" class="mt-2 min-h-11 w-full rounded-xl border-2 border-slate-300 px-3 focus:border-amber-500 focus:ring-4 focus:ring-amber-100"></label>
            <label class="text-sm font-semibold sm:col-span-2">Mensaje de bienvenida<textarea name="welcome_message" maxlength="300" rows="4" class="mt-2 min-h-28 w-full rounded-xl border-2 border-slate-300 px-3 py-2 focus:border-amber-500 focus:ring-4 focus:ring-amber-100">{{ old('welcome_message', $setting->welcome_message) }}</textarea></label>
            <div class="space-y-2"><label class="block text-sm font-semibold">Logo propio del Portal<input type="file" name="portal_logo" accept="image/jpeg,image/png,image/webp" class="mt-2 min-h-11 w-full rounded-xl border-2 border-slate-300 bg-white p-2 text-sm"></label>@if($setting->logoUrl($company))<img src="{{ $setting->logoUrl($company) }}" alt="Logo actual del Portal" class="h-16 w-16 rounded-xl border bg-white object-contain p-1">@endif<label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" name="remove_portal_logo" value="1"> Usar de nuevo el logo de la empresa</label></div>
            <div class="space-y-2"><label class="block text-sm font-semibold">Icono del Portal<input type="file" name="portal_icon" accept="image/png,image/webp" class="mt-2 min-h-11 w-full rounded-xl border-2 border-slate-300 bg-white p-2 text-sm"></label>@if($setting->iconUrl())<img src="{{ $setting->iconUrl() }}" alt="Icono actual del Portal" class="h-16 w-16 rounded-xl border bg-white object-contain p-1">@endif<label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" name="remove_portal_icon" value="1"> Quitar icono propio</label></div>
            <label class="text-sm font-semibold">Color principal<input type="color" name="brand_primary_color" required value="{{ old('brand_primary_color', $setting->brand_primary_color) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white p-1"></label>
            <label class="text-sm font-semibold">Color de acento<input type="color" name="brand_accent_color" required value="{{ old('brand_accent_color', $setting->brand_accent_color) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white p-1"></label>
            <fieldset class="grid grid-cols-1 gap-4 sm:col-span-2"><legend class="text-sm font-semibold">Redes sociales <span class="font-normal text-slate-500">(perfiles o publicaciones HTTPS)</span></legend>@foreach(['instagram_url'=>'Instagram','facebook_url'=>'Facebook','tiktok_url'=>'TikTok'] as $field=>$label)<label class="text-sm font-semibold">{{ $label }}<input type="url" inputmode="url" name="{{ $field }}" value="{{ old($field, $setting->{$field}) }}" placeholder="https://..." class="mt-2 min-h-11 w-full rounded-xl border-2 border-slate-300 px-3 focus:border-amber-500 focus:ring-4 focus:ring-amber-100"></label>@endforeach</fieldset>
            <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $setting->is_active))> Portal activo</label>
            <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="show_active_offers" value="1" @checked(old('show_active_offers', $setting->show_active_offers))> Mostrar ofertas activas automáticamente</label>
            <p class="text-xs text-slate-500 sm:col-span-2">El contenido destacado se controla marcando publicaciones como “Destacada”. Si no carga logo propio se usa el de la empresa. La firma “Hecho con MVS Commerce” permanece visible.</p>
            <button class="min-h-11 rounded-xl bg-slate-900 px-4 py-3 font-semibold text-white sm:col-span-2">Guardar configuración</button>
        </form>
    </section>
    </div>
    @endcan

    <section id="panel-vista-previa" role="tabpanel" aria-labelledby="tab-vista-previa" x-show="activeTab === 'vista-previa'" x-cloak class="rounded-2xl border bg-white p-4 sm:p-6"><h2 class="text-lg font-semibold">Vista previa y accesos</h2>@can('fidelidad.portal.ver')<div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">@foreach($customers->take(10) as $customer)<a target="_blank" href="{{ route('loyalty.portal-management.preview', $customer) }}" class="flex min-h-11 items-center rounded-xl border px-4 py-3 text-sm font-semibold">Ver como {{ $customer->name }}</a>@endforeach</div>@endcan @can('fidelidad.portal')<a href="{{ route('loyalty.accesses.index') }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-amber-600 px-4 font-semibold text-white">Administrar accesos seguros</a>@endcan</section>
    </x-tabs>
</div>
@endsection
