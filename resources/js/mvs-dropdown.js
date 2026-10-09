// MVS Commerce — helper compartido para desplegables Alpine.
// Desplaza la opción activa dentro de su contenedor sin mover la página.
// Uso: window.mvsScrollActiveIntoView(container, index)
(function () {
    if (window.mvsScrollActiveIntoView) { return; }

    function fallbackScroll(container, el) {
        const cTop = container.scrollTop;
        const cBottom = cTop + container.clientHeight;
        const eTop = el.offsetTop;
        const eBottom = eTop + el.offsetHeight;
        if (eTop < cTop) { container.scrollTop = eTop; }
        else if (eBottom > cBottom) { container.scrollTop = eBottom - container.clientHeight; }
    }

    window.mvsScrollActiveIntoView = function (container, index) {
        if (!container) { return; }
        const el = container.querySelector('[data-index="' + index + '"]');
        if (!el) { return; }
        try {
            el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        } catch (e) {
            fallbackScroll(container, el);
            return;
        }
        // Si el navegador ignora block:'nearest' y movió la página, corregir solo el contenedor.
        if (typeof el.offsetTop === 'number' && container.clientHeight) {
            const cTop = container.scrollTop;
            const cBottom = cTop + container.clientHeight;
            const eTop = el.offsetTop;
            const eBottom = eTop + el.offsetHeight;
            if (eTop < cTop || eBottom > cBottom) { fallbackScroll(container, el); }
        }
    };
})();
