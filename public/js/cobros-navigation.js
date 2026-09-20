(() => {
    const trigger = document.getElementById('cobros-more');
    const panel = document.getElementById('cobros-more-panel');
    const close = document.getElementById('cobros-more-close');
    if (!trigger || !panel || !close) return;

    let previousOverflow = '';
    trigger.addEventListener('click', () => {
        if (panel.open) return;
        previousOverflow = document.documentElement.style.overflow;
        panel.showModal();
        document.documentElement.style.overflow = 'hidden';
        trigger.setAttribute('aria-expanded', 'true');
    });
    close.addEventListener('click', () => panel.close());
    panel.addEventListener('click', event => {
        if (event.target !== panel) return;
        const box = panel.getBoundingClientRect();
        if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) {
            panel.close();
        }
    });
    // Native dialog handles Escape, focus containment and inert background.
    panel.addEventListener('close', () => {
        document.documentElement.style.overflow = previousOverflow;
        trigger.setAttribute('aria-expanded', 'false');
        trigger.focus();
    });
})();
