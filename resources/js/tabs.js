/**
 * P10 — Patrón UI reutilizable de pestañas MVS.
 * Componente Alpine para tabs con scroll horizontal en móvil,
 * estado activo sincronizado con hash URL, y accesibilidad por teclado.
 */

document.addEventListener('alpine:init', () => {

    Alpine.data('mvsTabs', (initialState = {}) => ({

        activeTab: initialState.activeTab || null,
        tabs: initialState.tabs || [],

        init() {
            this.$watch('activeTab', (value) => {
                if (value) {
                    history.replaceState(null, '', '#' + value);
                    this.$dispatch('mvs-tab-change', { tab: value });
                }
            });

            window.addEventListener('hashchange', () => {
                const hash = window.location.hash.slice(1);
                if (hash && this.tabs.some(t => t.id === hash)) {
                    this.activeTab = hash;
                }
            });
        },

        setTab(tabId) {
            if (this.tabs.some(t => t.id === tabId)) {
                this.activeTab = tabId;
            }
        },

        nextTab() {
            const currentIndex = this.tabs.findIndex(t => t.id === this.activeTab);
            if (currentIndex >= 0 && currentIndex < this.tabs.length - 1) {
                this.activeTab = this.tabs[currentIndex + 1].id;
            }
        },

        prevTab() {
            const currentIndex = this.tabs.findIndex(t => t.id === this.activeTab);
            if (currentIndex > 0) {
                this.activeTab = this.tabs[currentIndex - 1].id;
            }
        },

        focusAdjacentTab(direction) {
            const tabElements = Array.from(this.$root.querySelectorAll('[role="tab"]:not([aria-disabled="true"])'));
            const currentIndex = tabElements.indexOf(document.activeElement);
            if (currentIndex === -1 || tabElements.length === 0) {
                return;
            }
            const nextIndex = (currentIndex + direction + tabElements.length) % tabElements.length;
            tabElements[nextIndex].focus();
            tabElements[nextIndex].click();
        },

        focusBoundaryTab(boundary) {
            const tabElements = Array.from(this.$root.querySelectorAll('[role="tab"]:not([aria-disabled="true"])'));
            const target = boundary === 'last' ? tabElements.at(-1) : tabElements[0];
            target?.focus();
            target?.click();
        },

    }));

});
