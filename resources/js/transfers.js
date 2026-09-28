// UI only: stock is posted by the existing inventory service.
export function quantityUnits(value) {
    const text = String(value ?? '').trim();
    if (!/^\d+(?:\.\d{1,4})?$/.test(text)) return null;
    const [whole, fraction = ''] = text.split('.');
    return BigInt(whole) * 10000n + BigInt(fraction.padEnd(4, '0'));
}

export function differenceLabel(received, sent) {
    const actual = quantityUnits(received);
    const expected = quantityUnits(sent);
    if (actual === null || expected === null) return 'Ingrese una cantidad válida';
    const difference = actual - expected;
    if (difference === 0n) return 'Exacta';
    const magnitude = difference < 0n ? -difference : difference;
    const decimal = String(magnitude % 10000n).padStart(4, '0').replace(/0+$/, '');
    return (difference < 0n ? 'Faltante: ' : 'Sobrante: ') + String(magnitude / 10000n) + (decimal ? '.' + decimal : '');
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('transferCreate', (initialProducts, searchUrl) => ({
        lines: initialProducts, query: '', results: [], loading: false,
        searched: false, searchError: '', notice: '', submitting: false, requestId: 0,
        invalidateSearch() {
            this.requestId++;
            this.results = [];
            this.loading = false;
            this.searched = false;
            this.searchError = '';
        },
        async search() {
            const query = this.query.trim();
            const requestId = ++this.requestId;
            this.searchError = '';
            this.results = [];
            this.searched = false;
            if (!query) { this.loading = false; return; }
            this.loading = true;
            try {
                const url = new URL(searchUrl, window.location.origin);
                url.searchParams.set('q', query);
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok || response.redirected) throw new Error('search');
                const products = await response.json();
                if (!Array.isArray(products)) throw new Error('search');
                if (requestId === this.requestId) { this.results = products; this.searched = true; }
            } catch {
                if (requestId === this.requestId) this.searchError = 'No se pudo buscar. Revise su conexión y vuelva a intentar.';
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },
        add(product) {
            if (this.lines.some(line => line.id === product.id)) {
                this.notice = 'Este producto ya está agregado. Ajuste su cantidad en la lista.';
                return;
            }
            this.lines.push({ ...product, quantity: '1' });
            this.notice = 'Producto agregado.';
            this.query = '';
            this.invalidateSearch();
        },
        remove(index) { this.lines.splice(index, 1); this.notice = ''; },
        submit(event) {
            if (!this.lines.length || this.submitting) { event.preventDefault(); return; }
            this.submitting = true;
        },
    }));

    window.Alpine.data('transferReceipt', () => ({
        differenceLabel,
        tone(received, sent) {
            const actual = quantityUnits(received);
            const expected = quantityUnits(sent);
            if (actual === null || expected === null) return 'text-slate-500';
            return actual === expected ? 'text-emerald-700' : 'text-amber-800';
        },
    }));
});
