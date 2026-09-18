/**
 * MVS Commerce - Service Worker (Offline App Shell)
 *
 * Does NOT cache dynamic enterprise data. Enterprise data stays in IndexedDB;
 * authenticated HTML, APIs, sync and POST requests remain network-only.
 */

const CACHE_NAME = 'mvs-pos-shell-v5';
const CACHE_VERSION = 5;
const SHELL_ASSETS = [
  '/offline-shell.html',
  '/offline-shell.js',
  '/build/manifest.json',
];
const CACHE_FIRST_PATHS = [
  '/build/assets/',
  '/build/fonts/',
  '/images/',
  '/favicon.ico',
  '/manifest.json',
];
const NAVIGATION_PATHS = ['/', '/login', '/pos'];
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

function matchesPath(url, prefixes) {
  return prefixes.some((prefix) => url.pathname.startsWith(prefix));
}

function isNavigationRequest(request) {
  return request.mode === 'navigate'
    || (request.headers.get('accept') || '').includes('text/html');
}

function isStaticAsset(request) {
  return matchesPath(new URL(request.url), CACHE_FIRST_PATHS);
}

function isPosNavigation(request) {
  return isNavigationRequest(request) && NAVIGATION_PATHS.includes(new URL(request.url).pathname);
}

function isCacheableRequest(request) {
  const url = new URL(request.url);
  return request.method === 'GET' && url.origin === self.location.origin
    && (SHELL_ASSETS.includes(url.pathname) || isStaticAsset(request));
}

function isCacheableResponse(request, response) {
  if (!isCacheableRequest(request) || !response.ok || response.redirected) return false;
  if (/no-store|private/i.test(response.headers.get('cache-control') || '')) return false;
  const html = /text\/html|application\/xhtml\+xml/i.test(response.headers.get('content-type') || '');
  return !html || new URL(request.url).pathname === '/offline-shell.html';
}

function isNetworkOnlyRequest(request) {
  return matchesPath(new URL(request.url), NETWORK_ONLY_PATHS);
}

async function cacheStaticAssets(cache, urls) {
  for (const url of [...new Set(urls)]) {
    const request = new Request('/' + String(url).replace(/^\//, ''), { credentials: 'same-origin' });
    if (!isCacheableRequest(request)) continue;
    try {
      const response = await fetch(request);
      if (isCacheableResponse(request, response)) await cache.put(request, response);
    } catch (error) {
      console.warn(`[SW] Static shell asset skipped: ${url}`, error);
    }
  }
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(async (cache) => {
        const manifestResponse = await fetch('/build/manifest.json', { credentials: 'same-origin' });
        if (!manifestResponse.ok) throw new Error(`manifest HTTP ${manifestResponse.status}`);
        const manifest = await manifestResponse.json();
        const assets = Object.values(manifest)
          .flatMap((entry) => [entry.file, ...(entry.css || [])])
          .filter(Boolean)
          .map((asset) => `/build/${asset}`);
        await cacheStaticAssets(cache, [...SHELL_ASSETS, ...assets]);
      })
      .catch((error) => console.warn('[SW] Static shell preparation skipped:', error))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((names) => Promise.all(
        names
          .filter((name) => name.startsWith('mvs-pos-shell-') && name !== CACHE_NAME)
          .map((name) => caches.delete(name))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (isNetworkOnlyRequest(request) || request.method !== 'GET') return;
  if (isStaticAsset(request)) {
    event.respondWith(cacheFirst(request));
    return;
  }
  if (isPosNavigation(request)) {
    event.respondWith(networkFirstWithOfflineFallback(request));
    return;
  }
  if (isNavigationRequest(request)) return;
  event.respondWith(networkFirst(request));
});

async function cacheFirst(request) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);
  if (cached) return cached;
  try {
    const response = await fetch(request);
    if (isCacheableResponse(request, response)) await cache.put(request, response.clone());
    return response;
  } catch (_) {
    return new Response('Offline: asset not cached', { status: 503, statusText: 'Service Unavailable' });
  }
}

async function networkFirst(request) {
  // Unlisted GETs (including fetch('/pos') with Accept */*) are network-only.
  // Only the explicit generic shell resources may use this cache fallback.
  const cacheable = isCacheableRequest(request);
  try {
    const response = await fetch(request, cacheable ? {} : { cache: 'no-store' });
    if (isCacheableResponse(request, response)) {
      const cache = await caches.open(CACHE_NAME);
      await cache.put(request, response.clone());
    }
    return response;
  } catch (_) {
    const cached = cacheable
      ? await caches.open(CACHE_NAME).then((cache) => cache.match(request))
      : null;
    return cached || new Response('Offline: resource unavailable', {
      status: 503,
      statusText: 'Service Unavailable',
      headers: { 'Content-Type': 'text/plain; charset=utf-8' },
    });
  }
}

async function networkFirstWithOfflineFallback(request) {
  try {
    const response = await fetch(request, { cache: 'no-store' });
    // Authenticated POS HTML is never written to Cache Storage by default.
    // X-MVS-Offline-Shell is intentionally not required: only the generic
    // static shell is cached and it never contains authenticated context.
    return response;
  } catch (_) {
    const cache = await caches.open(CACHE_NAME);
    const shell = await cache.match('/offline-shell.html');
    return shell || offlineUnavailableResponse();
  }
}

function offlineUnavailableResponse() {
  return new Response(
    '<!doctype html><html lang="es"><head><meta charset="UTF-8"><title>MVS Commerce - Offline</title></head><body><h1>Offline no disponible</h1><p>Conecte la terminal al servidor para reaprovisionarla.</p></body></html>',
    { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503, statusText: 'Service Unavailable' }
  );
}

self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') self.skipWaiting();
  if (event.data === 'getVersion') event.ports[0].postMessage({ version: CACHE_VERSION, cacheName: CACHE_NAME });
  if (event.data === 'clearCache') {
    caches.delete(CACHE_NAME).then(() => event.ports[0].postMessage({ success: true }));
  }
});
