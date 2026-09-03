@props(['post', 'imageClass' => 'h-48 w-full'])
@php($imageUrl = $post->resolvedImageUrl())
<div {{ $attributes->class(['relative overflow-hidden rounded-xl border border-slate-200 bg-gradient-to-br from-slate-100 to-slate-200']) }}>
    <div class="flex {{ $imageClass }} items-center justify-center p-4 text-center text-slate-500" aria-hidden="true">
        <div>
            <svg class="mx-auto h-9 w-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m3 16 5-5 4 4 3-3 6 6M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Zm11-13h.01"/></svg>
            <span class="mt-2 block text-xs font-semibold">Imagen no disponible</span>
        </div>
    </div>
    @if($imageUrl)
        <img src="{{ $imageUrl }}" alt="Imagen de {{ $post->title }}" class="absolute inset-0 {{ $imageClass }} object-cover" onerror="this.remove()">
    @endif
</div>
