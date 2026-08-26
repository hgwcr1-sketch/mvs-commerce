@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-7xl space-y-5" data-responsive="360 768 1280">
    <header>
        <p class="text-sm font-semibold text-indigo-600">Productos</p>
        <h1 class="text-2xl font-bold text-slate-900">Centro de Etiquetas</h1>
        <p class="mt-1 text-sm text-slate-600">Selecciona productos, define cantidades y revisa el lote antes de imprimir.</p>
    </header>

    @if(session('success'))<div class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif

    @can('productos.etiquetas.configurar')
    <details class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <summary class="min-h-11 cursor-pointer py-2 font-semibold text-slate-800">Configuración de esta sucursal</summary>
        <form method="POST" action="{{ route('labels.settings.update') }}" class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            @csrf @method('PUT')
            <fieldset><legend class="form-label">Responsable de impresión</legend>
                <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="print_destinations[]" value="cashier" @checked(in_array('cashier', $setting->print_destinations ?? []))> Cajero</label>
                <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="print_destinations[]" value="administrator" @checked(in_array('administrator', $setting->print_destinations ?? []))> Administrador</label>
            </fieldset>
            <label><span class="form-label">Plantilla predeterminada</span><select name="default_template" class="form-input w-full">@foreach($templates as $key=>$label)<option value="{{ $key }}" @selected($setting->default_template===$key)>{{ $label }}</option>@endforeach</select></label>
            <label><span class="form-label">Tamaño predeterminado</span><select name="default_size" class="form-input w-full">@foreach($sizes as $key=>$label)<option value="{{ $key }}" @selected($setting->default_size===$key)>{{ $label }}</option>@endforeach</select></label>
            <label><span class="form-label">Encabezado de plantilla simple</span><input class="form-input w-full" name="custom_heading" maxlength="80" value="{{ $setting->custom_heading }}"></label>
            <button class="min-h-11 rounded-xl bg-slate-800 px-4 font-semibold text-white md:col-span-2 lg:col-span-4">Guardar configuración</button>
        </form>
    </details>
    @endcan

    <form method="GET" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 md:grid-cols-2 lg:grid-cols-5">
        <input name="search" value="{{ request('search') }}" class="form-input w-full lg:col-span-2" placeholder="Nombre, código o barcode">
        <select name="category_id" class="form-input w-full"><option value="">Todas las categorías</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id')==$category->id)>{{ $category->name }}</option>@endforeach</select>
        <select name="brand_id" class="form-input w-full"><option value="">Todas las marcas</option>@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(request('brand_id')==$brand->id)>{{ $brand->name }}</option>@endforeach</select>
        <select name="prints_label" class="form-input w-full"><option value="">Etiqueta: todos</option><option value="1" @selected(request('prints_label')==='1')>Sí imprime</option><option value="0" @selected(request('prints_label')==='0')>No imprime</option></select>
        <button class="min-h-11 rounded-xl bg-indigo-600 px-4 font-semibold text-white lg:col-span-5">Filtrar productos</button>
    </form>

    <div class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @forelse($products as $product)
            @php($code = $product->barcode ?: $product->barcodes->first()?->barcode)
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex gap-3"><input form="labelBatch" class="mt-1 h-6 w-6" type="checkbox" name="products[]" value="{{ $product->id }}" aria-label="Seleccionar {{ $product->name }}"><div class="min-w-0 flex-1"><h2 class="font-semibold text-slate-900">{{ $product->name }}</h2><p class="text-xs text-slate-500">{{ $product->internal_code }} · {{ $code ?: 'Sin barcode' }}</p><p class="mt-1 text-lg font-bold">₡{{ number_format($product->sale_price, 2, ',', '.') }}</p></div></div>
                <div class="mt-3 grid grid-cols-2 gap-3"><label><span class="text-xs font-medium">Cantidad</span><input form="labelBatch" type="number" inputmode="numeric" min="1" max="500" value="1" name="quantities[{{ $product->id }}]" class="form-input w-full text-right"></label>
                    <div><span class="text-xs font-medium">Imprime etiqueta</span><form method="POST" action="{{ route('labels.products.update', $product) }}">@csrf @method('PATCH')<input type="hidden" name="prints_label" value="{{ $product->prints_label ? 0 : 1 }}"><button class="mt-1 min-h-10 w-full rounded-lg {{ $product->prints_label ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">{{ $product->prints_label ? 'Sí' : 'No' }}</button></form></div></div>
            </article>
            @empty <p class="text-sm text-slate-500">No hay productos con estos filtros.</p> @endforelse
        </div>
        {{ $products->links() }}
        <form id="labelBatch" method="POST" action="{{ route('labels.preview') }}" class="sticky bottom-20 z-10 grid gap-3 rounded-2xl border border-slate-300 bg-white/95 p-4 shadow-xl backdrop-blur md:bottom-4 md:grid-cols-3">
            @csrf
            <select name="template" class="form-input w-full">@foreach($templates as $key=>$label)<option value="{{ $key }}" @selected($setting->default_template===$key)>{{ $label }}</option>@endforeach</select>
            <select name="size" class="form-input w-full">@foreach($sizes as $key=>$label)<option value="{{ $key }}" @selected($setting->default_size===$key)>{{ $label }}</option>@endforeach</select>
            <button class="min-h-11 rounded-xl bg-indigo-600 px-4 font-bold text-white">Vista previa del lote</button>
        </form>
    </div>
</div>
@endsection
