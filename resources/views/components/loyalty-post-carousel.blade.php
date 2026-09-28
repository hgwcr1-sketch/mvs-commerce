@props(['post', 'imageClass' => 'h-48 w-full', 'href' => null])

@php
    $images = $post->resolvedImageUrls();
    $hasMultiple = count($images) > 1;
    $imagesJson = $hasMultiple
        ? json_encode($images, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)
        : null;
@endphp

<div
    {{ $attributes->class(['relative overflow-hidden rounded-xl border border-slate-200 bg-gradient-to-br from-slate-100 to-slate-200']) }}
    @if($hasMultiple)
        x-data="{
            current: 0,
            images: {!! $imagesJson !!},
            touchStartX: 0,
            touchEndX: 0,
            autoplayTimer: null,
            autoplayDelay: 4000,
            go(index) { this.current = ((index % this.images.length) + this.images.length) % this.images.length; this.resetAutoplay(); },
            next() { this.go(this.current + 1); },
            prev() { this.go(this.current - 1); },
            startAutoplay() { this.stopAutoplay(); this.autoplayTimer = setInterval(() => this.next(), this.autoplayDelay); },
            stopAutoplay() { if (this.autoplayTimer) { clearInterval(this.autoplayTimer); this.autoplayTimer = null; } },
            resetAutoplay() { this.stopAutoplay(); this.startAutoplay(); },
            handleTouchStart(e) { this.touchStartX = e.changedTouches[0].screenX; this.stopAutoplay(); },
            handleTouchEnd(e) { this.touchEndX = e.changedTouches[0].screenX; this.swipe(); this.startAutoplay(); },
            swipe() { const diff = this.touchStartX - this.touchEndX; if (Math.abs(diff) > 50) { diff > 0 ? this.next() : this.prev(); } },
        }"
        x-init="startAutoplay()"
        @mouseenter="stopAutoplay()"
        @mouseleave="startAutoplay()"
        @touchstart="handleTouchStart($event)"
        @touchend="handleTouchEnd($event)"
    @endif
>
    @if($hasMultiple)
        <div class="relative {{ $imageClass }} bg-white">
            <template x-for="(image, index) in images" :key="index">
                <a
                    x-show="current === index"
                    x-transition:enter="transition ease-in-out duration-300"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in-out duration-300"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    @if($href) href="{{ $href }}" target="_blank" rel="noopener noreferrer" @endif
                    class="absolute inset-0 flex items-center justify-center bg-white p-1 {{ $imageClass }}"
                    @if($href) aria-label="{{ $post->ctaLabel() }}: {{ $post->title }}" @endif
                >
                    <img :src="image.startsWith('/') ? image : '/' + image" :alt="'Imagen ' + (index + 1)" class="h-full w-full object-contain" loading="lazy">
                </a>
            </template>
            <button
                type="button"
                x-show="images.length > 1"
                x-cloak
                @click="prev()"
                class="absolute left-2 top-1/2 z-10 -translate-y-1/2 min-h-11 min-w-11 rounded-full bg-black/40 p-2 text-white backdrop-blur-sm transition hover:bg-black/60 focus:outline-none focus:ring-2 focus:ring-white"
                aria-label="Anterior"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
            </button>
            <button
                type="button"
                x-show="images.length > 1"
                x-cloak
                @click="next()"
                class="absolute right-2 top-1/2 z-10 -translate-y-1/2 min-h-11 min-w-11 rounded-full bg-black/40 p-2 text-white backdrop-blur-sm transition hover:bg-black/60 focus:outline-none focus:ring-2 focus:ring-white"
                aria-label="Siguiente"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </button>
        </div>
        <div class="flex items-center justify-center gap-1.5 px-3 py-2" x-show="images.length > 1" x-cloak>
            <template x-for="(image, index) in images" :key="index">
                <button
                    type="button"
                    @click="go(index)"
                    class="grid min-h-11 min-w-11 place-items-center rounded-full transition focus:outline-none focus:ring-2 focus:ring-slate-400"
                    :aria-label="'Ir a imagen ' + (index + 1)"
                ><span :class="current === index ? 'w-4 bg-slate-800' : 'w-2 bg-slate-300'" class="h-2 rounded-full" aria-hidden="true"></span></button>
            </template>
        </div>
    @else
        <div class="flex {{ $imageClass }} items-center justify-center p-4 text-center text-slate-500" aria-hidden="true">
            <div>
                <svg class="mx-auto h-10 w-10 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M18.75 21H5.25A2.25 2.25 0 013 18.75V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25v13.5a2.25 2.25 0 01-2.25 2.25z"/></svg>
                <span class="mt-2 block text-xs font-semibold">Imagen no disponible</span>
            </div>
        </div>
        @if(count($images) === 1)
            @if($href)
                <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $post->ctaLabel() }}: {{ $post->title }}" class="absolute inset-0 flex items-center justify-center bg-white p-1 {{ $imageClass }}">
                    <img src="{{ $images[0] }}" alt="Imagen de {{ $post->title }}" class="h-full w-full object-contain" loading="lazy">
                </a>
            @else
                <div class="absolute inset-0 flex items-center justify-center bg-white p-1 {{ $imageClass }}">
                    <img src="{{ $images[0] }}" alt="Imagen de {{ $post->title }}" class="h-full w-full object-contain" loading="lazy">
                </div>
            @endif
        @endif
    @endif
</div>
