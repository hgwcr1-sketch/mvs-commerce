/* CobrOS-only service worker (scope: /cobros).
 * Fase 5B + endurecimiento 5C.
 *
 * - Caché SOLO de assets estáticos públicos (manifest/iconos). Nunca se
 *   almacena HTML dinámico ni respuestas autenticadas: sin datos sensibles
 *   en Cache Storage.
 * - Borradores locales aislados por `owner` (usuario:empresa) y eliminados
 *   al cerrar sesión (mensaje cobros:clear-drafts).
 * - Scrub de credenciales en borradores: se eliminan _token/CSRF/password/
 *   cookies, pero se PRESERVAN los tokens de idempotencia (client_token).
 * - El cobro financiero SOLO se aplica en línea vía 3A/3B; este worker
 *   jamás confirma un pago.
 */

'use strict';

const COBROS_SCOPE = '/cobros';

const VERSION = 'cobros-pwa-v2';

const DB_NAME = 'cobros-offline-v1';
const STORE_DRAFTS = 'drafts';

// Claves que jamás deben persistirse en un borrador.
const CREDENTIAL_KEYS = ['_token', 'password', 'password_confirmation', 'remember', 'authorization', 'cookie', 'csrf', 'session'];

function isScopePath(pathname) {
    return pathname === COBROS_SCOPE || pathname.startsWith(COBROS_SCOPE + '/');
}

/** Elimina credenciales; conserva client_token (idempotencia) y payload_hash. */
function scrubDraft(draft) {
    const clean = {};
    for (const [key, value] of Object.entries(draft)) {
        if (CREDENTIAL_KEYS.includes(key)) {
            continue;
        }
        if (key === 'owner' && typeof value === 'string') {
            clean.owner = value;
            continue;
        }
        clean[key] = value;
    }
    if (!clean.owner) {
        return null; // Borrador sin dueño: no se persiste (aislamiento).
    }
    return clean;
}

// --------------------------------------------------------- Database
function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE_DRAFTS)) {
                db.createObjectStore(STORE_DRAFTS, { keyPath: 'id' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function putDraft(draft) {
    const clean = scrubDraft(draft);
    if (!clean) return false;
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_DRAFTS, 'readwrite');
        tx.objectStore(STORE_DRAFTS).put(clean);
        tx.oncomplete = () => resolve(true);
        tx.onerror = () => reject(tx.error);
    });
}

async function clearDrafts(owner) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_DRAFTS, 'readwrite');
        const store = tx.objectStore(STORE_DRAFTS);
        if (owner) {
            // Aislamiento: solo borradores de ese usuario:empresa.
            const req = store.openCursor();
            req.onsuccess = () => {
                const cursor = req.result;
                if (!cursor) return;
                if (cursor.value.owner === owner) cursor.delete();
                cursor.continue();
            };
        } else {
            store.clear();
        }
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

// --------------------------------------------------------- Lifecycle
self.oninstall = async (event) => {
    event.waitUntil(
        (async () => {
            // Solo assets públicos estáticos: jamás HTML autenticado.
            const staticCache = await caches.open(VERSION + '-static');
            await staticCache.addAll([
                '/manifest-cobros.json',
                '/icons/cobros/icon-192x192.png',
                '/icons/cobros/icon-512x512.png',
            ]);
            await self.skipWaiting();
            // claim() NO se espera en install: sin worker activo aún la promesa
            // se rechaza (InvalidStateError), el waitUntil falla, el worker pasa
            // a redundant y el navegador borra el registro. claim() se ejecuta en
            // activate (más abajo), donde sí hay worker activo.
        })()
    );
};

self.onactivate = (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();
            await Promise.all(keys.filter((k) => k !== VERSION + '-static').map((k) => caches.delete(k)));
            await self.clients.claim();
        })()
    );
};

self.onfetch = (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    // Scope estricto: nunca intercepta rutas de Commerce.
    if (!isScopePath(url.pathname)) return;

    event.respondWith(
        (async () => {
            // Cache-first SOLO para assets estáticos precacheados (públicos).
            const cached = await caches.match(req);
            if (cached) return cached;

            // Nunca se hace cache de respuestas dinámicas/autenticadas.
            try {
                return await fetch(req);
            } catch (err) {
                // Sin red: respuesta honesta, sin fingir éxito operativo.
                if (req.mode === 'navigate' || req.headers.get('accept')?.includes('text/html')) {
                    return new Response(
                        '<!DOCTYPE html><html lang="es"><meta charset="utf-8">' +
                        '<title>Sin conexión — MVS CobrOS</title>' +
                        '<body style="font-family:system-ui;padding:24px">' +
                        '<h1>Sin conexión</h1>' +
                        '<p>No hay red. Los cobros solo se confirman en línea; ' +
                        'intente de nuevo cuando vuelva la señal.</p>' +
                        '<p><a href="' + COBROS_SCOPE + '">Reintentar</a></p></body></html>',
                        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                    );
                }
                return new Response(JSON.stringify({ offline: true }), {
                    status: 503,
                    headers: { 'Content-Type': 'application/json' },
                });
            }
        })()
    );
};

// --------------------------------------------------------- Drafts / sync
self.addEventListener('message', (event) => {
    const data = event.data;
    if (!data || typeof data !== 'object') return;

    if (data.type === 'cobros:capture-draft' && data.draft) {
        event.waitUntil(
            putDraft({ id: data.draft.id || String(Date.now()), ...data.draft })
        );
    }

    if (data.type === 'cobros:clear-drafts') {
        // Logout: elimina los borradores del dueño indicado (o todos).
        event.waitUntil(clearDrafts(data.owner || null));
    }
});

self.addEventListener('sync', (event) => {
    if (event.tag === 'cobros-sync') {
        // Reintento de sincronización: solo notifica a las ventanas vivas;
        // la persistencia real la decide el servidor bajo 3A/3B.
        event.waitUntil(
            self.clients.matchAll({ type: 'window' }).then((clients) => {
                clients.forEach((client) => client.postMessage({ type: 'cobros:sync-ready' }));
            })
        );
    }
});
