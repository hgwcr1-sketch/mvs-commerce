/**
 * R01 — Navegación responsive.
 * JS mínimo y aislado: estado del sidebar desktop (compacto/hover/fijado)
 * y ocultamiento temporal de la barra inferior móvil al escribir.
 */

document.addEventListener('alpine:init', () => {

    window.Alpine.data('sidebarShell', () => ({

        pinned: localStorage.getItem('mvs.sidebar.pinned') === '1',

        hovered: false,

        desktop: window.matchMedia('(min-width: 1024px)').matches,

        init() {

            const query = window.matchMedia('(min-width: 1024px)');

            const onChange = event => { this.desktop = event.matches; };

            if (query.addEventListener) {

                query.addEventListener('change', onChange);

            } else {

                query.addListener(onChange);

            }

        },

        get isExpanded() {

            return this.hovered || (this.pinned && this.desktop);

        },

        togglePinned() {

            this.pinned = ! this.pinned;

            localStorage.setItem('mvs.sidebar.pinned', this.pinned ? '1' : '0');

        },

    }));

});

// Barras fijas (navegación inferior y barra Total/Cobrar del POS):
// se ocultan mientras un campo de texto tiene el foco para no competir
// con el teclado móvil.

const bottomNavInputSelector = 'input, textarea, select';

const fixedBarsSelector = '#bottom-nav, #pos-sticky-bar';

function getFixedBars() {

    return document.querySelectorAll(fixedBarsSelector);

}

function hideBottomNavForFocus(event) {

    const bars = getFixedBars();

    if (! bars.length || ! (event.target instanceof Element)) {

        return;

    }

    if (event.target.matches(bottomNavInputSelector)) {

        bars.forEach(bar => bar.classList.add('nav-bar-hidden'));

    }

}

function showBottomNavAfterBlur() {

    const bars = getFixedBars();

    if (! bars.length) {

        return;

    }

    setTimeout(() => {

        const active = document.activeElement;

        if (! active || ! (active.matches && active.matches(bottomNavInputSelector))) {

            bars.forEach(bar => bar.classList.remove('nav-bar-hidden'));

        }

    }, 60);

}

document.addEventListener('focusin', hideBottomNavForFocus);

document.addEventListener('focusout', showBottomNavAfterBlur);
