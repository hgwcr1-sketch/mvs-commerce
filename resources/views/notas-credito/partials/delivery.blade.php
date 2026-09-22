@php
    $title = $delivery['title'] ?? 'Nota de crédito generada';
    $subtitle = $delivery['subtitle'] ?? null;
    $warning = $delivery['warning'] ?? 'Este código se muestra una sola vez. Entréguelo al cliente para que pueda utilizar su nota de crédito.';
    $issuedAmount = $delivery['issued_amount'] ?? null;
    $issuedAt = $delivery['issued_at'] ?? null;
    $expiresAt = $delivery['expires_at'] ?? null;
    $hasExpiry = array_key_exists('expires_at', $delivery);
    $closeUrl = $delivery['close_url'] ?? null;
    $closeLabel = $delivery['close_label'] ?? 'Cerrar';
@endphp

<main class="mx-auto w-full max-w-xl px-4 py-6 sm:py-10">

    <div class="rounded-2xl border border-primary bg-white p-4 shadow-sm sm:p-6">
        <header class="mb-5">
            <p class="text-xs font-black uppercase tracking-widest text-primary">MVS Commerce</p>
            <h2 class="text-xl font-bold text-black sm:text-2xl">{{ $title }}</h2>
            @if ($subtitle)
                <p class="mt-1 text-sm text-slate-600">{{ $subtitle }}</p>
            @endif
        </header>

        <dl class="grid gap-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-xs font-black uppercase tracking-wide text-slate-500">Número de nota</dt>
                <dd class="font-mono text-lg font-bold text-black">{{ $delivery['credit_note_number'] }}</dd>
            </div>
            @if ($issuedAmount !== null)
                <div>
                    <dt class="text-xs font-black uppercase tracking-wide text-slate-500">Monto</dt>
                    <dd class="text-lg font-bold text-black">₡ {{ number_format((float) $issuedAmount, 2) }}</dd>
                </div>
            @endif
            <div class="sm:col-span-2">
                <dt class="text-xs font-black uppercase tracking-wide text-slate-500">Código para el cliente</dt>
                <dd id="delivery-application-code" class="mt-1 inline-block rounded-xl bg-black px-4 py-2 font-mono text-2xl font-black tracking-widest text-white sm:text-3xl">{{ $delivery['application_code'] }}</dd>
            </div>
            @if ($issuedAt !== null)
                <div>
                    <dt class="text-xs font-black uppercase tracking-wide text-slate-500">Fecha</dt>
                    <dd class="text-slate-700">{{ \Illuminate\Support\Carbon::parse($issuedAt)->format('d/m/Y H:i') }}</dd>
                </div>
            @endif
            @if ($hasExpiry)
                <div>
                    <dt class="text-xs font-black uppercase tracking-wide text-slate-500">Vencimiento</dt>
                    <dd class="text-slate-700">
                        @if ($expiresAt !== null)
                            {{ \Illuminate\Support\Carbon::parse($expiresAt)->format('d/m/Y') }}
                        @else
                            Sin vencimiento
                        @endif
                    </dd>
                </div>
            @endif
        </dl>

        <div class="mt-5 rounded-xl border border-primary/40 bg-primary/10 p-4">
            <p class="text-sm font-semibold text-black">{{ $warning }}</p>
        </div>

        <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <button id="delivery-copy-button" type="button"
                class="min-h-11 cursor-pointer rounded-lg bg-primary px-4 py-2.5 text-sm font-bold text-black hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary">
                Copiar código
            </button>
            <button onclick="window.print()" type="button"
                class="min-h-11 cursor-pointer rounded-lg border-2 border-black bg-white px-4 py-2.5 text-sm font-bold text-black hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-primary">
                Imprimir comprobante
            </button>
            @if ($closeUrl)
                <a href="{{ $closeUrl }}"
                    class="min-h-11 cursor-pointer rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 hover:bg-slate-100">
                    {{ $closeLabel }}
                </a>
            @endif
        </div>
    </div>

    <div id="delivery-print-sheet">
        <div>
            <div style="font-weight:bold;">{{ $company?->trade_name ?? '' }}</div>
            <div>MVS Commerce</div>
            @if ($company?->legal_name)
                <div>{{ $company->legal_name }}</div>
            @endif
            @if ($company?->identification_number)
                <div>Identificación: {{ $company->identification_number }}</div>
            @endif
            @if ($branch?->name)
                <div>Sucursal: {{ $branch->name }}</div>
            @endif
        </div>
        <div style="margin:12px 0 8px; font-weight:bold; text-align:center;">NOTA DE CRÉDITO</div>
        <div>Número: {{ $delivery['credit_note_number'] }}</div>
        @if ($issuedAmount !== null)
            <div>Monto: ₡ {{ number_format((float) $issuedAmount, 2) }}</div>
        @endif
        <div>Código de aplicación: {{ $delivery['application_code'] }}</div>
        @if ($issuedAt !== null)
            <div>Fecha: {{ \Illuminate\Support\Carbon::parse($issuedAt)->format('d/m/Y H:i') }}</div>
        @endif
        @if ($hasExpiry)
            <div>Vencimiento: {{ $expiresAt !== null ? \Illuminate\Support\Carbon::parse($expiresAt)->format('d/m/Y') : 'Sin vencimiento' }}</div>
        @endif
        <div style="margin-top:12px;">Conserve este número y este código para utilizar su nota de crédito.</div>
    </div>

</main>

<style>
    #delivery-print-sheet {
        display: none;
    }

    @media print {
        body * {
            visibility: hidden;
        }

        #delivery-print-sheet {
            display: block !important;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            padding: 8px;
            font-size: 12px;
        }

        #delivery-print-sheet,
        #delivery-print-sheet * {
            visibility: visible;
        }

        #delivery-print-sheet * {
            font-family: 'Courier New', Courier, monospace;
            color: #000 !important;
        }
    }
</style>

<script>
(() => {
    const button = document.getElementById('delivery-copy-button');
    const code = document.getElementById('delivery-application-code');
    if (!button || !code) {
        return;
    }

    const copy = async (value) => {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(value);
            return;
        }
        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    };

    button.addEventListener('click', async () => {
        const value = code.textContent.trim();
        const original = button.textContent;
        try {
            await copy(value);
            button.textContent = '¡Copiado!';
        } catch (_) {
            button.textContent = 'No se pudo copiar';
        }
        window.setTimeout(() => {
            button.textContent = original;
        }, 1600);
    });
})();
</script>