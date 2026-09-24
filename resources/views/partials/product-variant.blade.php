@props(['product'])

@if ($product && (is_object($product) || is_array($product)))
    @php
        $style = is_array($product) ? ($product['style_name'] ?? null) : $product->style?->name;
        $size  = is_array($product) ? ($product['size_name']  ?? null) : $product->size?->name;
        $color = is_array($product) ? ($product['color_name'] ?? null) : $product->color?->name;
    @endphp

    <span class="inline-flex flex-wrap gap-1 text-xs text-slate-500">
        @if ($style)
            <span class="px-1.5 py-0.5 bg-amber-50 text-amber-700 rounded border border-amber-100">{{ $style }}</span>
        @endif
        @if ($size)
            <span class="px-1.5 py-0.5 bg-slate-100 rounded border border-slate-200">{{ $size }}</span>
        @endif
        @if ($color)
            <span class="px-1.5 py-0.5 bg-slate-100 rounded border border-slate-200">{{ $color }}</span>
        @endif
    </span>
@endif
