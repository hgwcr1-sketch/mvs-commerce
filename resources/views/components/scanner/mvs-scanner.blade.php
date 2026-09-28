{{--
    R02-B — Hoja del escáner por cámara.
    Componente reutilizable: consume el componente Alpine `mvsScanner`
    (resources/js/scanner) y emite 'mvs-scan' por cada lectura válida.
    No contiene lógica de negocio; el consumidor decide qué hacer con el código.
    Prop opcional: video-id (identificador del elemento <video> a utilizar).
--}}
@props(['videoId' => 'pos-scanner-video'])
<div x-data="mvsScanner"
     x-cloak
     x-show="open"
     @mvs-scanner-open.window="openScanner($event.detail?.videoId)"
     @keydown.escape.window="close()"
     @click.self="close()"
     class="fixed inset-0 z-[130] flex flex-col bg-slate-950/90 p-3 sm:p-5"
     role="dialog"
     aria-modal="true"
     aria-label="Escanear código con cámara">

    <div class="mx-auto flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-3xl bg-slate-900 shadow-2xl">
        <header class="flex items-center justify-between gap-3 px-4 py-3 text-white sm:px-5">
            <div>
                <h2 class="text-lg font-bold">Escanear código</h2>
                <p class="text-xs text-slate-300">Código de barras o QR de producto</p>
            </div>
            <button type="button"
                    @click="close()"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-white/25 text-2xl hover:bg-white/10"
                    aria-label="Cerrar escáner">×</button>
        </header>

        <div class="relative aspect-[3/4] w-full overflow-hidden bg-black sm:aspect-video">
            <video id="{{ $videoId }}"
                   playsinline
                   muted
                   autoplay
                   class="h-full w-full object-cover"></video>

            <div class="pointer-events-none absolute inset-6 rounded-2xl border-2 border-dashed border-white/40" aria-hidden="true"></div>

            <span x-show="starting" x-cloak
                  class="absolute inset-x-0 bottom-3 text-center text-sm font-semibold text-white">
                Iniciando cámara…
            </span>
        </div>

        <div class="space-y-2 px-4 py-3 text-sm sm:px-5">
            <p x-show="status && !error" x-text="status" class="rounded-xl bg-slate-800 px-3 py-2 text-slate-200"></p>
            <p x-show="error" x-text="error" class="rounded-xl bg-red-50 px-3 py-2 font-semibold text-red-700"></p>
            <button type="button"
                    x-show="canToggleCamera"
                    x-cloak
                    @click="toggleCamera"
                    :disabled="starting"
                    class="min-h-[44px] w-full rounded-xl border border-slate-500 px-4 py-2.5 font-semibold text-white hover:bg-slate-800 disabled:opacity-50">
                Cambiar cámara
            </button>
        </div>
    </div>
</div>
