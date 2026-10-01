/* CobrOS-only service worker (scope: /cobros).
 * Fase 5B: cierra 1B con instalación real y conectividad tolerante a red.
 *
 * - Solo captura BORRADOS locales idempotentes, nunca pago/cobro ni
 *   escritura financiera. El cobro solo se aplica en línea vía 3A/3B.
 * - Estado de conexión expuesto como self.cobrosOnline.
 * - No intercepta rutas de Commerce (scope /cobros).
 */

'use strict';

// Corre dentro del scope /cobros; cualquier petición fuera es ignorada.
const COBrosScope = '/cobros';

const VERSION = 'cobros-pwa-v1';

const DB_NAME = 'cobros-offline-v1';
const STORE_DRAFTS = 'drafts';
const STORE_WATCH = 'watch';

function isScopeMatch(scriptURL) {
    return scriptURL.startsWith(COBrosScope);
}

function truncate(str, max = 64) {
    if (str.length <= max) return str;
    return str.slice(0, max - 1) + '\u2026';
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
            if (!db.objectStoreNames.contains(STORE_WATCH)) {
                db.createObjectStore(STORE_WATCH, { keyPath: 'url' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function putDraft(draft) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_DRAFTS, 'readwrite');
        tx.objectStore(STORE_DRAFTS).put(draft);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

async function getDrafts() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_DRAFTS, 'readonly');
        const req = tx.objectStore(STORE_DRAFTS).getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror = () => reject(req.error);
    });
}

async function clearDrafts() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_DRAFTS, 'readwrite');
        tx.objectStore(STORE_DRAFTS).clear();
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

// --------------------------------------------------------- Activation
self.oninstall = async (event) => {
    event.waitUntil(
        (async () => {
            const db = await openDb();
            // Foreground cache de recursos estáticos del shell (contenido falso,
            // reemplaza el dinámico); el shell mismo lo maneja fetch para hits.
            const staticCache = await caches.open(VERSION + '-static');
            await staticCache.addAll([
                COBrosScope + '/',
                COBrosScope + '/manifest-cobros.json',
                COBrosScope + '/icons/cobros/icon-192x192.png',
                COBrosScope + '/icons/cobros/icon-512x512.png',
            ]);
            await self.skipWaiting();
            await self.clients.claim();
        })()
    );
};

self.onactivate = (event) => {
    event.waitUntil(
        (async () => {
            const keys = await self.indexedDB.databases();
            const toDelete = keys.filter((k) => k.name !== DB_NAME && k.version < VERSION);
            await Promise.all(toDelete.map((k) => self.indexedDB.deleteDatabase(k.name)));
        })()
    );
};

self.onfetch = (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    // Solo dentro de /cobros.
    if (!isScopeMatch(new URL(req.url).pathname)) {
        return;
    }

    // Lectura: caché primero para recursos estáticos del shell; sino redirige
    // al servidor local (dev) o al manejador offline.
    event.respondWith(
        (async () => {
            const cached = await caches.match(req);
            if (cached) return cached;

            const url = new URL(req.url);
            // Ventana de edición: recibe el estado online del worker.
            if (url.pathname.startsWith(COBrosScope + '/online-status')) {
                return new Response(
                    JSON.stringify({ online: self.cobrosOnline ?? navigator.onLine, t: Date.now() }),
                    { headers: { 'Content-Type': 'application/json' } }
                );
            }

            // Solo los recursos estáticos del shell se pusieron en cache.
            if (
                url.pathname.startsWith(COBrosScope + '/icons/') ||
                url.pathname.endsWith('.js') ||
                url.pathname.endsWith('.css') ||
                url.pathname.endsWith('.json') ||
                url.pathname === '/' ||
                url.pathname.endsWith('/manifest-cobros.json')
            ) {
                return new Response('{"offline":"capture"}', { headers: { 'Content-Type': 'application/json' } });
            }

            return breadcrumbRequest(req);
        })()
    );
};

// --------------------------------------------------------- Offline / sync
self.cobrosOnline = navigator.onLine;

self.addEventListener('online', () => {
    self.cobrosOnline = true;
});

self.addEventListener('offline', () => {
    self.cobrosOnline = false;
});

self.addEventListener('sync', (event) => {
    if (event.tag === 'cobros-sync') {
        event.retryCount++;
        event.waitUntil(
            (async () => {
                const drafts = await getDrafts();
                // Solo réplica borradores idempotentes, NO pagos ni anulaciones.
                // El servicio backend confirmará todo lo escrito bajo 3A/3B.
                const remote = await replayDrafts(drafts);
                // Remota: nada. El servidor decide el resultado y el cliente
                // lo vuelve a leer al volver online.
            })()
        );
    }
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'cobros:capture-draft') {
        event.waitUntil(captureDraft(event.data.draft));
    }
});

// --- Captura de borrados (idempotente, solo lectura/ingreso local) ---
async function captureDraft(draft) {
    const id = draft.id || (draft.indexedId || String(Date.now()));
    draft.id = id;
    draft.capturedAt = Date.now();
    draft.status = 'pending';
    await putDraft(draft);

    // Despierta al sync periodicamente cuando vuelva online.
    self.registration?.sync?.register('cobros-sync')?.catch(() => {});
}

async function replayDrafts(drafts) {
    const remote = [];
    for (const d of drafts) {
        if (d.status === 'completed' || d.status === 'rejected') continue;
        // Aplica al backend solo si el borrador es de captura de operación
        // que el front decidió remarcar para sincronización. Aquí se deja
        // la decisión al endpoint (3A/3B): no se reescribe dinero sin
        // confirmación del servidor.
        remote.push({ id: d.id, payloadHash: d.payloadHash || null });
    }
    return remote;
}

// --- Breadcrumb auxiliar (fallback para recursos desconocidos) ---
function breadcrumbRequest(req) {
    return new Promise((resolve) => {
        const timer = setTimeout(() => {
            resolve(
                new Response(
                    JSON.stringify({ offline: true, path: req.url, ts: Date.now() }),
                    { headers: { 'Content-Type': 'application/json' } }
                )
            );
        }, 6000);
        // Fallback a fetch normal (servidor). Mantenido para no romper PRG.
        fetch(req).then((res) => {
            clearTimeout(timer);
            if (res.ok) return res;
            resolve(breadcrumbFallback(req));
        }).catch(() => resolve(breadcrumbFallback(req)));
    });
}

function breadcrumbFallback(req) {
    return new Response(
        JSON.stringify({ offline: true, path: req.url, ts: Date.now() }),
        { headers: { 'Content-Type': 'application/json' } }
    );
}
