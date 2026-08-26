@extends('layouts.app')
@section('title', 'Portal de Clientes')
@section('content')
<div class="space-y-6">
    <header><h1 class="text-2xl font-semibold text-slate-900">Portal de Clientes</h1><p class="mt-1 text-sm text-slate-500">Administra la experiencia del portal de esta empresa sin alterar el catálogo ni las reglas de puntos.</p></header>
    @if(session('success'))<div class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="rounded-xl bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>@endif

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

    <x-tabs :tabs="$portalTabs" active-tab="acceso-general" variant="pills" aria-label="Secciones del Portal de Clientes">

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
                    @if($company->logo)<img src="{{ asset('storage/'.$company->logo) }}" alt="Logo de {{ $company->trade_name }}" class="h-10 w-10 rounded-lg border bg-white object-contain p-1">@endif
                    <span class="font-semibold" style="color:{{ $setting->brand_primary_color }}">{{ $company->trade_name }}</span>
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
    <section id="publicaciones" class="rounded-2xl border bg-white p-4 sm:p-6"><h2 class="text-lg font-semibold">Nueva publicación</h2><form method="POST" action="{{ route('loyalty.portal-management.posts.store') }}" enctype="multipart/form-data" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">@csrf
        <label class="text-sm font-semibold">Tipo<select name="type" class="mt-2 min-h-11 w-full rounded-xl border-slate-300">@foreach(['new_product'=>'Nuevo producto','offer'=>'Oferta','promotion'=>'Promoción','notice'=>'Aviso'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">Producto existente (opcional)<select name="product_id" class="mt-2 min-h-11 w-full rounded-xl border-slate-300"><option value="">Contenido propio</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }} · ₡{{ number_format((float)($product->special_price ?? $product->sale_price), 0, ',', '.') }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">Título<input name="title" required maxlength="120" class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label><label class="text-sm font-semibold">Imagen propia (opcional)<input type="file" name="image" accept="image/*" class="mt-2 min-h-11 w-full rounded-xl border p-2"></label>
        <label class="text-sm font-semibold md:col-span-2">Mensaje<textarea name="message" maxlength="500" rows="3" class="mt-2 w-full rounded-xl border-slate-300"></textarea></label><label class="text-sm font-semibold">Desde<input type="datetime-local" name="starts_at" class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label><label class="text-sm font-semibold">Hasta<input type="datetime-local" name="ends_at" class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label>
        <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="is_active" value="1" checked> Activa</label><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="is_featured" value="1"> Destacada</label><label class="text-sm font-semibold">Orden<input type="number" name="sort_order" value="0" min="0" max="9999" class="mt-2 min-h-11 w-full rounded-xl border-slate-300"></label><button class="min-h-11 self-end rounded-xl bg-amber-600 px-5 font-semibold text-white">Publicar</button>
    </form></section>
    </div>

    <div id="panel-destacados" role="tabpanel" aria-labelledby="tab-destacados" x-show="activeTab === 'destacados'" x-cloak>
    <section id="destacados" class="space-y-3"><h2 class="text-lg font-semibold">Publicaciones y productos destacados</h2>@forelse($posts as $post)<article class="rounded-xl border bg-white p-4"><form method="POST" action="{{ route('loyalty.portal-management.posts.update', $post) }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-3 md:grid-cols-4">@csrf @method('PUT')<select name="type" class="min-h-11 rounded-xl border-slate-300">@foreach(['new_product'=>'Nuevo producto','offer'=>'Oferta','promotion'=>'Promoción','notice'=>'Aviso'] as $value=>$label)<option value="{{ $value }}" @selected($post->type===$value)>{{ $label }}</option>@endforeach</select><select name="product_id" class="min-h-11 rounded-xl border-slate-300"><option value="">Contenido propio</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected($post->product_id===$product->id)>{{ $product->name }}</option>@endforeach</select><input name="title" value="{{ $post->title }}" required class="min-h-11 rounded-xl border-slate-300"><input name="sort_order" type="number" value="{{ $post->sort_order }}" min="0" max="9999" class="min-h-11 rounded-xl border-slate-300"><textarea name="message" maxlength="500" class="rounded-xl border-slate-300 md:col-span-2">{{ $post->message }}</textarea><input type="datetime-local" name="starts_at" value="{{ $post->starts_at?->format('Y-m-d\TH:i') }}" class="min-h-11 rounded-xl border-slate-300"><input type="datetime-local" name="ends_at" value="{{ $post->ends_at?->format('Y-m-d\TH:i') }}" class="min-h-11 rounded-xl border-slate-300"><input type="file" name="image" accept="image/*" class="min-h-11 rounded-xl border p-2"><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked($post->is_active)> Activa</label><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="is_featured" value="1" @checked($post->is_featured)> Destacada</label><button class="min-h-11 rounded-xl bg-slate-900 px-4 text-white">Guardar</button></form><form method="POST" action="{{ route('loyalty.portal-management.posts.destroy', $post) }}" class="mt-2" onsubmit="return confirm('¿Eliminar publicación?')">@csrf @method('DELETE')<button class="min-h-10 text-sm font-semibold text-red-700">Eliminar</button></form></article>@empty<p class="rounded-xl border border-dashed bg-white p-6 text-center text-sm text-slate-500">No hay publicaciones.</p>@endforelse</section>
    </div>
    @endcan

    @can('fidelidad.portal.enlaces')
    <div id="panel-enlaces" role="tabpanel" aria-labelledby="tab-enlaces" x-show="activeTab === 'enlaces'" x-cloak>
    <section id="enlaces" class="rounded-2xl border bg-white p-4 sm:p-6"><h2 class="text-lg font-semibold">Enlaces y botones</h2><form method="POST" action="{{ route('loyalty.portal-management.links.store') }}" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4">@csrf<select name="type" class="min-h-11 rounded-xl border-slate-300">@foreach(['buy'=>'Comprar ahora','store'=>'Tienda/web','catalog'=>'Catálogo WhatsApp','whatsapp'=>'WhatsApp','other'=>'Otro'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select><input name="label" required maxlength="80" placeholder="Texto del botón" class="min-h-11 rounded-xl border-slate-300"><input type="url" name="url" required placeholder="https://..." class="min-h-11 rounded-xl border-slate-300"><button class="min-h-11 rounded-xl bg-amber-600 px-4 font-semibold text-white">Agregar</button><input type="hidden" name="is_active" value="1"></form><div class="mt-5 space-y-3">@foreach($links as $link)<form method="POST" action="{{ route('loyalty.portal-management.links.update', $link) }}" class="grid grid-cols-1 gap-2 md:grid-cols-5">@csrf @method('PUT')<input type="hidden" name="type" value="{{ $link->type }}"><input name="label" value="{{ $link->label }}" class="min-h-11 rounded-xl border-slate-300"><input type="url" name="url" value="{{ $link->url }}" class="min-h-11 rounded-xl border-slate-300 md:col-span-2"><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked($link->is_active)> Activo</label><button class="min-h-11 rounded-xl bg-slate-900 text-white">Guardar</button></form><form method="POST" action="{{ route('loyalty.portal-management.links.destroy', $link) }}">@csrf @method('DELETE')<button class="text-sm font-semibold text-red-700">Eliminar</button></form>@endforeach</div></section>
    </div>
    @endcan

    @can('fidelidad.portal.configurar')
    <div id="panel-configuracion" role="tabpanel" aria-labelledby="tab-configuracion" x-show="activeTab === 'configuracion'" x-cloak>
    <section id="configuracion" class="rounded-2xl border bg-white p-4 sm:p-6"><h2 class="text-lg font-semibold">Configuración</h2><form method="POST" action="{{ route('loyalty.portal-management.settings.update') }}" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">@csrf @method('PUT')<label class="text-sm font-semibold sm:col-span-2">Mensaje de bienvenida<textarea name="welcome_message" maxlength="300" class="mt-2 w-full rounded-xl border-slate-300">{{ $setting->welcome_message }}</textarea></label><label class="text-sm font-semibold">Color principal<input type="color" name="brand_primary_color" required value="{{ $setting->brand_primary_color }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white p-1"></label><label class="text-sm font-semibold">Color de acento<input type="color" name="brand_accent_color" required value="{{ $setting->brand_accent_color }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white p-1"></label><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked($setting->is_active)> Portal activo</label><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="show_active_offers" value="1" @checked($setting->show_active_offers)> Mostrar ofertas activas automáticamente</label><p class="text-xs text-slate-500 sm:col-span-2">El logo y nombre provienen de la empresa activa. La firma “Hecho con MVS Commerce” permanece visible.</p><button class="min-h-11 rounded-xl bg-slate-900 px-4 font-semibold text-white sm:col-span-2">Guardar configuración</button></form></section>
    </div>
    @endcan

    <section id="panel-vista-previa" role="tabpanel" aria-labelledby="tab-vista-previa" x-show="activeTab === 'vista-previa'" x-cloak class="rounded-2xl border bg-white p-4 sm:p-6"><h2 class="text-lg font-semibold">Vista previa y accesos</h2>@can('fidelidad.portal.ver')<div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">@foreach($customers->take(10) as $customer)<a target="_blank" href="{{ route('loyalty.portal-management.preview', $customer) }}" class="flex min-h-11 items-center rounded-xl border px-4 py-3 text-sm font-semibold">Ver como {{ $customer->name }}</a>@endforeach</div>@endcan @can('fidelidad.portal')<a href="{{ route('loyalty.accesses.index') }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-amber-600 px-4 font-semibold text-white">Administrar accesos seguros</a>@endcan</section>
    </x-tabs>
</div>
@endsection
