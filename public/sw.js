/**
 * MVS Commerce — Service Worker (Offline App Shell)
 *
 * Versioned cache for POS app shell assets.
 * Does NOT cache dynamic enterprise data (products, customers, etc.) —
 * that remains in IndexedDB.
 *
 * Cache name: mvs-pos-shell-v1
 * Update: increment version in CACHE_NAME when assets change.
 */

const CACHE_NAME = 'mvs-pos-shell-v1';
const CACHE_VERSION = 1;

/**
 * Assets to cache for offline POS operation.
 * Only static, versioned assets — no dynamic API responses.
 */
const SHELL_ASSETS = [
  '/',
  '/pos',
  '/build/assets/app-BzbkRNK6.css',
  '/build/assets/app-BFp434cI.js',
];

/**
 * Cache-only paths — these are static files that never change without a version bump.
 * Matched by exact pathname.
 */
const CACHE_FIRST_PATHS = [
  '/build/assets/',
  '/build/fonts/',
  '/images/',
  '/favicon.ico',
  '/manifest.json',
];

/**
 * Navigation paths that should use network-first with offline fallback.
 * These are the POS routes that need to work offline.
 */
const NAVIGATION_PATHS = [
  '/pos',
];

/**
 * API paths that must NEVER be cached — always network-only.
 * Dynamic enterprise data goes through IndexedDB, not Cache Storage.
 */
const NETWORK_ONLY_PATHS = [
  '/api/',
  '/mvs/offline/',
  '/pos/productos/',
  '/pos/clientes/',
  '/pos/fidelidad/',
  '/pos/cobrar',
  '/pos/suspender',
  '/pos/suspendidas',
  '/pos/ventas/',
  '/caja/',
  '/pedidos/',
  '/cotizaciones/',
];

/**
 * Check if a URL pathname matches a prefix list.
 */
function matchesPath(url, prefixes) {
  const pathname = url.pathname;
  return prefixes.some(prefix => pathname.startsWith(prefix));
}

/**
 * Check if request is a navigation request (HTML page load).
 */
function isNavigationRequest(request) {
  return request.mode === 'navigate'
    || (request.headers.get('accept') || '').includes('text/html');
}

/**
 * Check if request is for a static asset.
 */
function isStaticAsset(request) {
  const url = new URL(request.url);
  return matchesPath(url, CACHE_FIRST_PATHS);
}

/**
 * Check if request is for POS navigation.
 */
function isPosNavigation(request) {
  if (!isNavigationRequest(request)) return false;
  const url = new URL(request.url);
  return matchesPath(url, NAVIGATION_PATHS);
}

/**
 * Check if request is an API call that must never be cached.
 */
function isNetworkOnlyRequest(request) {
  const url = new URL(request.url);
  return matchesPath(url, NETWORK_ONLY_PATHS);
}

/**
 * Install event — cache the app shell assets.
 */
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        return cache.addAll(SHELL_ASSETS.map(url => new Request(url, { credentials: 'same-origin' })))
          .catch((error) => {
            console.warn('[SW] Some shell assets failed to cache:', error);
          });
      })
      .then(() => self.skipWaiting())
  );
});

/**
 * Activate event — clean up old MVS Offline caches only.
 * Does NOT delete caches from other applications.
 */
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((cacheNames) => {
        return Promise.all(
          cacheNames
            .filter((name) => name.startsWith('mvs-pos-shell-') && name !== CACHE_NAME)
            .map((name) => caches.delete(name))
        );
      })
      .then(() => self.clients.claim())
  );
});

/**
 * Fetch event — route requests based on strategy.
 */
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Only handle same-origin requests
  if (url.origin !== self.location.origin) {
    return;
  }

  // Network-only for APIs and POST requests — never cache dynamic data
  if (isNetworkOnlyRequest(request) || request.method !== 'GET') {
    return;
  }

  // Static assets: cache-first
  if (isStaticAsset(request)) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // POS navigation: network-first with offline fallback
  if (isPosNavigation(request)) {
    event.respondWith(networkFirstWithOfflineFallback(request));
    return;
  }

  // Default: network-first for other GET requests
  event.respondWith(networkFirst(request));
});

/**
 * Cache-first strategy: serve from cache, fallback to network, update cache.
 */
async function cacheFirst(request) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);

  if (cached) {
    return cached;
  }

  try {
    const response = await fetch(request);
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    return new Response('Offline: asset not cached', { status: 503, statusText: 'Service Unavailable' });
  }
}

/**
 * Network-first strategy: try network, fallback to cache.
 */
async function networkFirst(request) {
  const cache = await caches.open(CACHE_NAME);

  try {
    const response = await fetch(request);
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await cache.match(request);
    if (cached) {
      return cached;
    }
    throw error;
  }
}

/**
 * Network-first with offline fallback for POS navigation.
 * If both network and cache fail, return a minimal offline shell.
 */
async function networkFirstWithOfflineFallback(request) {
  const cache = await caches.open(CACHE_NAME);

  try {
    const response = await fetch(request);
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await cache.match(request);
    if (cached) {
      return cached;
    }

    // Fallback to root (which is cached) or minimal offline page
    const rootCached = await cache.match('/');
    if (rootCached) {
      return rootCached;
    }

    return new Response(
      `<!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>MVS Commerce — Sin conexión</title>
        <style>
          body { font-family: system-ui, sans-serif; text-align: center; padding: 2rem; background: #f3f4f6; }
          .container { max-width: 400px; margin: 0 auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
          h1 { color: #1f2937; }
          p { color: #6b7280; }
          button { background: #2563eb; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 6px; cursor: pointer; font-size: 1rem; }
          button:hover { background: #1d4ed8; }
        </style>
      </head>
      <body>
        <div class="container">
          <h1>MVS Commerce</h1>
          <p>No hay conexión a Internet. La aplicación se cargará cuando se restablezca la conexión.</p>
          <button onclick="window.location.reload()">Reintentar</button>
        </div>
      </body>
      </html>`,
      { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503, statusText: 'Service Unavailable' }
    );
  }
}

/**
 * Message handler for skip waiting and cache management.
 */
self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') {
    self.skipWaiting();
  }

  if (event.data === 'getVersion') {
    event.ports[0].postMessage({ version: CACHE_VERSION, cacheName: CACHE_NAME });
  }

  if (event.data === 'clearCache') {
    caches.delete(CACHE_NAME).then(() => {
      event.ports[0].postMessage({ success: true });
    });
  }
});