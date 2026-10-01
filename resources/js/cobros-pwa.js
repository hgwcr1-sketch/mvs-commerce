/**
 * MVS CobrOS — PWA mobile del cobrador y conectividad (Fase 5B).
 *
 * - Registra el service worker scope /cobros (nunca Commerce).
 * - Expone estado de conexión en `window.cobrosOnline`.
 * - Gestiona `beforeinstallprompt`: instala el PWA sin romper la experiencia
 *   de escritorio/móvil; no requiere app-installed para operar.
 * - Aviso de "sin conexión" en el shell; nunca confirma cobro offline.
 * - No guarda pagos ni anulaciones locales: solo borradores idempotente
 *   que confirmará el backend bajo 3A/3B.
 * - 5C: borradores aislados por owner (usuario:empresa), credenciales
 *   jamás persistidas (solo client_token de idempotencia) y borradores
 *   eliminados al cerrar sesión.
 */

(function () {
    'use strict';

    // Owner aislado: usuario:empresa de la sesión activa (layout data-*).
    const body = document.body;
    const ownerKey = body && body.dataset.cobrosOwner
        ? body.dataset.cobrosOwner
        : null;

    window.cobrosPwa = {
        online: false,
        installing: false,
        deferredPrompt: null,
        serviceWorker: null,
        owner: ownerKey,
    };

    // ------------------------------------------- Borradores aislados 5C
    const CREDENTIAL_KEYS = ['_token', 'password', 'password_confirmation', 'remember', 'authorization', 'cookie', 'csrf', 'session'];

    function scrub(draft) {
        const clean = {};
        for (const key of Object.keys(draft)) {
            if (CREDENTIAL_KEYS.indexOf(key) !== -1) continue;
            clean[key] = draft[key];
        }
        if (!ownerKey) return null;
        clean.owner = ownerKey;
        return clean;
    }

    /** Captura un borrador local aislado por owner. Nunca envía dinero. */
    window.cobrosPwa.captureDraft = function (draft) {
        const clean = scrub(draft || {});
        if (!clean || !navigator.serviceWorker || !navigator.serviceWorker.controller) return false;
        clean.id = clean.id || String(Date.now());
        navigator.serviceWorker.controller.postMessage({ type: 'cobros:capture-draft', draft: clean });
        return true;
    };

    /** Elimina los borradores del owner actual (logout). */
    function clearDrafts() {
        if (navigator.serviceWorker && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ type: 'cobros:clear-drafts', owner: ownerKey });
        }
    }

    // Logout: limpia borradores locales antes de salir (ambos formularios del shell).
    document.querySelectorAll('form[action*="cobros/logout"]').forEach((form) => {
        form.addEventListener('submit', clearDrafts, { once: true });
    });

    // --------------------------------------------------------- Estado
    function updateOnline() {
        const online = navigator.onLine;
        window.cobrosPwa.online = online;
        const el = document.querySelector('.cobros-connection');
        if (!el) return;
        if (online) {
            el.remove();
            return;
        }
        // Ya no añadir duplicados: el componente Blade solo se renderiza si
        // la sesión marca offline; aquí mantenemos la sincronía de eventos.
        const rect = el.getBoundingClientRect();
        if (rect.width === 0 && rect.height === 0) {
            el.remove();
        }
    }

    window.addEventListener('online', updateOnline);
    window.addEventListener('offline', updateOnline);

    // --------------------------------------------------------- Instalación
    const triggerInstall = () => {
        if (window.cobrosPwa.installing || !window.cobrosPwa.deferredPrompt) return;
        window.cobrosPwa.installing = true;
        window.cobrosPwa.deferredPrompt.prompt();
        window.cobrosPwa.deferredPrompt.userChoice
            .then(() => {
                window.cobrosPwa.installing = false;
                window.cobrosPwa.deferredPrompt = null;
            })
            .catch(() => {
                window.cobrosPwa.installing = false;
                window.cobrosPwa.deferredPrompt = null;
            });
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        window.cobrosPwa.deferredPrompt = event;
        const btn = document.getElementById('cobros-install');
        if (btn) {
            btn.classList.remove('hidden');
        }
    });

    window.addEventListener('appinstalled', () => {
        const btn = document.getElementById('cobros-install');
        if (btn) btn.classList.add('hidden');
        window.cobrosPwa.installing = false;
        window.cobrosPwa.deferredPrompt = null;
    });

    // Botón de instalación (oculto hasta que el PWA está disponible).
    const installRoot = document.getElementById('cobros-install-root');
    if (installRoot) {
        const label = document.getElementById('cobros-install-label');
        const btn = document.getElementById('cobros-install');
        if (btn) {
            btn.addEventListener('click', () => {
                if (window.cobrosPwa.installing) return;
                if (window.cobrosPwa.deferredPrompt) {
                    triggerInstall();
                } else {
                    // Fallback: abre los ajustes del sistema.
                    window.open('/', '_blank');
                }
            });
        }
    }

    // --------------------------------------------------------- Service worker
    function registerSw() {
        if (typeof navigator.serviceWorker === 'undefined') {
            setStatus('sw-desktop', 'contador>Sin service worker', 'mxs:text-xs');
            return;
        }

        const scope = '/cobros';
        const swUrl = '/sw-cobros.js';

        // Solo registrarlo dentro de /cobros.
        if (!location.pathname.startsWith(scope)) {
            return;
        }

        navigator.serviceWorker.register(swUrl, { scope }).then((reg) => {
            window.cobrosPwa.serviceWorker = reg;
            setStatus('sw-desktop', 'contador>Service worker listo', 'mxs:text-xs');
            reg.addEventListener('updatefound', () => {
                const newSw = reg.installing;
                if (newSw) {
                    newSw.addEventListener('statechange', () => {
                        if (newSw.state === 'installed' && navigator.serviceWorker.controller === null) {
                            setStatus('sw-desktop', 'contador>Nueva versión disponible', 'mxs:text-xs');
                        }
                    });
                }
            });
        }).catch((err) => {
            setStatus('sw-desktop', 'contador>Service worker no disponible', 'mxs:text-xs');
            console.warn('[cobros-pwa] SW registration error:', err);
        });
    }

    function setStatus(id, label, cls) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = label;
        el.className = cls || 'mxs:text-xs';
    }

    // El SW avisa cuando hay borradores listos para revisar en línea.
    if (navigator.serviceWorker) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'cobros:sync-ready') {
                window.dispatchEvent(new CustomEvent('cobros:sync-ready'));
            }
        });
    }

    // --------------------------------------------------------- Fine-grained
    registerSw();

    // Ver estado de conexión exacto en la ventana actual.
    updateOnline();

    // Expone el estado de conexión a scripts exógenos.
    window.addEventListener('cobros:online-status', () => updateOnline());
})();
