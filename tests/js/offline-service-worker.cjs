const { it } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const origin = 'http://127.0.0.1:8000';
const shell = '<div id="mvs-offline-shell"></div>';
const privateHtml = '<meta name="csrf-token" content="private"><div x-data="posTerminal"></div>';

function worker() {
  const handlers = {}, stores = new Map(), calls = [];
  let offline = false, responseOverride = null;
  const key = value => new URL(typeof value === 'string' ? value : value.url, origin).href;
  const caches = {
    keys: async () => [...stores.keys()],
    delete: async name => stores.delete(name),
    open: async name => {
      if (!stores.has(name)) stores.set(name, new Map());
      const store = stores.get(name);
      return {
        put: async (req, response) => store.set(key(req), response.clone()),
        match: async req => store.get(key(req))?.clone(),
      };
    },
  };
  const ctx = vm.createContext({ URL, Response, console, caches,
    Request: class extends Request { constructor(url, options) { super(new URL(url, origin), options); } },
    indexedDB: { deleteDatabase() { throw new Error('IndexedDB must never be touched'); } },
    self: { location: { origin }, addEventListener: (type, handler) => { handlers[type] = handler; },
      skipWaiting: async () => {}, clients: { claim: async () => {} } },
    fetch: async (request, options) => {
      calls.push({ url: key(request), options });
      if (offline) throw new TypeError('backend OFF');
      if (responseOverride) return responseOverride.clone();
      const pathname = new URL(key(request)).pathname;
      if (pathname === '/build/manifest.json') return Response.json({
        'resources/js/app.js': { file: 'assets/app-test.js', css: ['assets/app-test.css'] },
        chunk: { file: 'assets/chunk-test.js' },
      });
      if (pathname === '/offline-shell.html') return new Response(shell, { headers: { 'Content-Type': 'text/html' } });
      if (pathname === '/pos' || pathname === '/') return new Response(privateHtml, { headers: { 'Content-Type': 'text/html' } });
      return new Response('static asset', { headers: { 'Content-Type': 'text/javascript' } });
    },
  });
  vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../../public/sw.js'), 'utf8'), ctx);
  const currentName = vm.runInContext('CACHE_NAME', ctx);
  return {
    stores, calls, currentName, caches,
    offline: () => { offline = true; },
    respond: value => { responseOverride = value; },
    run: type => { let work; handlers[type]({ waitUntil: promise => { work = promise; } }); return work; },
    dispatch: (pathname, { mode = 'cors', accept = '*/*', method = 'GET' } = {}) => {
      let work;
      handlers.fetch({ request: { url: origin + pathname, mode, method, headers: new Headers({ accept }) },
        waitUntil: () => {}, respondWith: promise => { work = promise; } });
      return work;
    },
  };
}

it('precache contains generic shell and all Vite build chunks, never POS/root HTML', async () => {
  const w = worker();
  await w.run('install');
  const urls = [...w.stores.get(w.currentName).keys()];
  for (const pathname of ['/offline-shell.html', '/offline-shell.js', '/build/manifest.json', '/build/assets/app-test.js', '/build/assets/chunk-test.js']) {
    assert.ok(urls.includes(origin + pathname), pathname);
  }
  assert.ok(!urls.includes(origin + '/pos'));
  assert.ok(!urls.includes(origin + '/'));
});

for (const pathname of ['/pos', '/']) {
  for (const mode of ['navigate', 'cors']) {
    it(`${mode} GET ${pathname} never caches private HTML (including Accept */*)`, async () => {
      const w = worker();
      const response = await w.dispatch(pathname, { mode });
      if (response) assert.equal(await response.text(), privateHtml);
      for (const store of w.stores.values()) assert.equal(store.has(origin + pathname), false);
    });
  }
}

it('offline navigation serves generic shell even if a private POS entry exists', async () => {
  const w = worker();
  const cache = await w.caches.open(w.currentName);
  await cache.put('/offline-shell.html', new Response(shell));
  await cache.put('/pos', new Response(privateHtml));
  w.offline();
  assert.equal(await (await w.dispatch('/pos', { mode: 'navigate' })).text(), shell);
});

it('navigation bypasses HTTP cached authenticated HTML', async () => {
  const w = worker();
  await w.dispatch('/pos', { mode: 'navigate' });
  assert.equal(w.calls[0].options?.cache, 'no-store');
});

for (const [pathname, method] of [['/api/private', 'GET'], ['/mvs/offline/sync', 'POST'], ['/pos/cobrar', 'POST']]) {
  it(`${method} ${pathname} remains network-only`, () => {
    const w = worker();
    assert.equal(w.dispatch(pathname, { method }), undefined);
    assert.equal(w.stores.size, 0);
  });
}

it('an unlisted dynamic API GET is never cached', async () => {
  const w = worker();
  w.respond(Response.json({ private: true }));
  await w.dispatch('/clientes/datos');
  assert.equal(w.stores.size, 0);
});

it('HTML returned under an asset URL is never cached', async () => {
  const w = worker();
  w.respond(new Response(privateHtml, { headers: { 'Content-Type': 'text/html' } }));
  await w.dispatch('/build/assets/broken.js');
  for (const store of w.stores.values()) assert.equal(store.size, 0);
});

it('activation purges old MVS caches only and does not touch IndexedDB', async () => {
  const w = worker();
  for (const name of ['mvs-pos-shell-v2', 'mvs-pos-shell-v3', 'mvs-pos-shell-v4', 'other-app', w.currentName]) await w.caches.open(name);
  await w.run('activate');
  assert.deepEqual([...w.stores.keys()].sort(), ['other-app', w.currentName].sort());
  assert.notEqual(w.currentName, 'mvs-pos-shell-v4');
});

it('HTTP errors never activate the offline shell', async () => {
  const w = worker();
  await (await w.caches.open(w.currentName)).put('/offline-shell.html', new Response(shell));
  w.respond(new Response('unauthorized', { status: 401 }));
  const response = await w.dispatch('/pos', { mode: 'navigate' });
  assert.equal(response.status, 401);
});
