@php
    $editing = isset($post) && $post;
    $fieldId = $editing ? 'post-'.$post->id : 'post-new';
    $controlClass = 'mt-2 min-h-11 w-full rounded-xl border-2 border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-4 focus:ring-amber-100';
@endphp

<div class="space-y-1">
    <label for="{{ $fieldId }}-type" class="block text-sm font-semibold text-slate-800">Tipo de publicación</label>
    <select id="{{ $fieldId }}-type" name="type" class="{{ $controlClass }}">
        @foreach(['new_product'=>'Nuevo producto','offer'=>'Oferta','promotion'=>'Promoción','notice'=>'Aviso'] as $value=>$label)
            <option value="{{ $value }}" @selected(old('type', $post->type ?? 'new_product') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</div>
<div class="space-y-1">
    <label for="{{ $fieldId }}-product" class="block text-sm font-semibold text-slate-800">Producto asociado <span class="font-normal text-slate-500">(opcional)</span></label>
    <select id="{{ $fieldId }}-product" name="product_id" class="{{ $controlClass }}">
        <option value="">Contenido propio, sin producto</option>
        @foreach($products as $product)
            <option value="{{ $product->id }}" @selected((int) old('product_id', $post->product_id ?? 0) === (int) $product->id)>{{ $product->name }} · ₡{{ number_format((float)($product->special_price ?? $product->sale_price), 0, ',', '.') }}</option>
        @endforeach
    </select>
    <p class="text-xs text-slate-500">Si no carga una imagen propia, se utilizará la imagen disponible del producto.</p>
</div>
<div class="space-y-1">
    <label for="{{ $fieldId }}-title" class="block text-sm font-semibold text-slate-800">Título</label>
    <input id="{{ $fieldId }}-title" name="title" value="{{ old('title', $post->title ?? '') }}" required maxlength="120" class="{{ $controlClass }}" placeholder="Ej. Nueva promoción de temporada">
</div>
<div class="space-y-2" x-data="{ preview: null, choose(event) { const file = event.target.files[0]; if (this.preview) URL.revokeObjectURL(this.preview); this.preview = file ? URL.createObjectURL(file) : null } }">
    <label for="{{ $fieldId }}-image" class="block text-sm font-semibold text-slate-800">Imagen propia <span class="font-normal text-slate-500">(opcional)</span></label>
    <input id="{{ $fieldId }}-image" type="file" name="image" accept="image/jpeg,image/png,image/webp" @change="choose($event)" class="{{ $controlClass }} file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:font-semibold file:text-slate-700">
    <p class="text-xs text-slate-500">JPG, PNG o WebP. Máximo 3 MB. La imagen propia tiene prioridad.</p>
    <div x-show="preview" x-cloak class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
        <img :src="preview" alt="Vista previa de la imagen seleccionada" class="h-48 w-full object-cover">
    </div>
    @if($editing)
        <x-loyalty-post-image :post="$post" image-class="h-36 w-full" class="mt-2" />
    @endif
</div>
@if($editing)
    <div class="space-y-2">
        <label class="block text-sm font-semibold text-slate-800">Galería de imágenes <span class="font-normal text-slate-500">(adicionales)</span></label>
        <p class="text-xs text-slate-500">Agregue varias imágenes para carrusel en el portal. Se recomiendan imágenes del mismo tamaño para evitar saltos.</p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            @foreach($post->images as $galleryImage)
                <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white">
                    <img src="{{ asset('storage/'.$galleryImage->path) }}" alt="Galería" class="h-32 w-full object-cover">
                    <form method="POST" action="{{ route('loyalty.portal-management.posts.images.delete', [$post, $galleryImage]) }}" class="absolute right-1 top-1" onsubmit="return confirm('¿Eliminar esta imagen de la galería?')">@csrf @method('DELETE')<button type="submit" class="flex min-h-8 min-w-8 items-center justify-center rounded-full bg-black/50 text-white backdrop-blur-sm transition hover:bg-black/70" aria-label="Eliminar imagen"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button></form>
                </div>
            @endforeach
            @if($post->images->isEmpty())
                <p class="col-span-full text-xs text-slate-500">Sin imágenes adicionales en la galería.</p>
            @endif
        </div>
        <form method="POST" action="{{ route('loyalty.portal-management.posts.images.add', $post) }}" enctype="multipart/form-data" class="mt-3">
            @csrf
            <label for="{{ $fieldId }}-gallery" class="block text-xs font-semibold text-slate-700">Agregar imagen a la galería</label>
            <div class="mt-1 flex flex-col gap-2 sm:flex-row">
                <input id="{{ $fieldId }}-gallery" type="file" name="gallery[]" accept="image/jpeg,image/png,image/webp" multiple class="{{ $controlClass }} file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:font-semibold file:text-slate-700">
                <button type="submit" class="min-h-11 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Agregar</button>
            </div>
            <p class="mt-1 text-xs text-slate-500">Puede seleccionar varias a la vez. Se guardan al final del orden actual.</p>
        </form>
    </div>
@endif
<div class="space-y-1 md:col-span-2">
    <label for="{{ $fieldId }}-message" class="block text-sm font-semibold text-slate-800">Mensaje <span class="font-normal text-slate-500">(opcional)</span></label>
    <textarea id="{{ $fieldId }}-message" name="message" maxlength="500" rows="4" class="{{ $controlClass }} min-h-28" placeholder="Detalle que verá el cliente">{{ old('message', $post->message ?? '') }}</textarea>
</div>
<div class="space-y-1">
    <label for="{{ $fieldId }}-cta-type" class="block text-sm font-semibold text-slate-800">Acción <span class="font-normal text-slate-500">(opcional)</span></label>
    <select id="{{ $fieldId }}-cta-type" name="cta_type" class="{{ $controlClass }}">
        <option value="">Sin botón</option>
        @foreach(\App\Models\LoyaltyPortalPost::CTA_LABELS as $value => $label)
            <option value="{{ $value }}" @selected(old('cta_type', $post->cta_type ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</div>
<div class="space-y-1">
    <label for="{{ $fieldId }}-cta-url" class="block text-sm font-semibold text-slate-800">Destino del botón <span class="font-normal text-slate-500">(URL segura)</span></label>
    <input id="{{ $fieldId }}-cta-url" type="url" inputmode="url" name="cta_url" value="{{ old('cta_url', $post->cta_url ?? '') }}" maxlength="500" class="{{ $controlClass }}" placeholder="https://...">
    <p class="text-xs text-slate-500">La misma URL vuelve clickeable la imagen o el carrusel. Puede dirigir a WhatsApp, reserva, producto o sitio externo; no crea carrito ni pago.</p>
</div>
<div class="space-y-1">
    <label for="{{ $fieldId }}-starts" class="block text-sm font-semibold text-slate-800">Visible desde <span class="font-normal text-slate-500">(opcional)</span></label>
    <input id="{{ $fieldId }}-starts" type="datetime-local" name="starts_at" value="{{ old('starts_at', isset($post) ? $post->starts_at?->format('Y-m-d\TH:i') : '') }}" class="{{ $controlClass }}">
</div>
<div class="space-y-1">
    <label for="{{ $fieldId }}-ends" class="block text-sm font-semibold text-slate-800">Visible hasta <span class="font-normal text-slate-500">(opcional)</span></label>
    <input id="{{ $fieldId }}-ends" type="datetime-local" name="ends_at" value="{{ old('ends_at', isset($post) ? $post->ends_at?->format('Y-m-d\TH:i') : '') }}" class="{{ $controlClass }}">
</div>
<div class="space-y-1">
    <label for="{{ $fieldId }}-order" class="block text-sm font-semibold text-slate-800">Orden</label>
    <input id="{{ $fieldId }}-order" type="number" inputmode="numeric" name="sort_order" value="{{ old('sort_order', $post->sort_order ?? 0) }}" min="0" max="9999" class="{{ $controlClass }}">
</div>
<div class="flex flex-col gap-2 sm:flex-row sm:items-center">
    <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border-2 border-slate-300 px-3 py-2 text-sm font-semibold text-slate-800 focus-within:border-amber-500 focus-within:ring-4 focus-within:ring-amber-100"><input type="checkbox" name="is_active" value="1" class="h-5 w-5 rounded border-slate-400 text-amber-600 focus:ring-amber-500" @checked(old('is_active', $post->is_active ?? true))> Activa</label>
    <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border-2 border-slate-300 px-3 py-2 text-sm font-semibold text-slate-800 focus-within:border-amber-500 focus-within:ring-4 focus-within:ring-amber-100"><input type="checkbox" name="is_featured" value="1" class="h-5 w-5 rounded border-slate-400 text-amber-600 focus:ring-amber-500" @checked(old('is_featured', $post->is_featured ?? false))> Destacada</label>
</div>
