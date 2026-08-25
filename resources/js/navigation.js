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

// Barra inferior: se oculta mientras un campo de texto tiene el foco
// para no competir con el teclado móvil.

const bottomNavInputSelector = 'input, textarea, select';

function hideBottomNavForFocus(event) {

    const bar = document.getElementById('bottom-nav');

    if (! bar || ! (event.target instanceof Element)) {

        return;

    }

    if (event.target.matches(bottomNavInputSelector)) {

        bar.classList.add('nav-bar-hidden');

    }

}

function showBottomNavAfterBlur() {

    const bar = document.getElementById('bottom-nav');

    if (! bar) {

        return;

    }

    setTimeout(() => {

        const active = document.activeElement;

        if (! active || ! (active.matches && active.matches(bottomNavInputSelector))) {

            bar.classList.remove('nav-bar-hidden');

        }

    }, 60);

}

document.addEventListener('focusin', hideBottomNavForFocus);

document.addEventListener('focusout', showBottomNavAfterBlur);
