/**
 * MVS Commerce — Offline Phase 4A Tests
 *
 * Tests for: Service Worker registration, cache strategy,
 * public key delivery, offline readiness check.
 *
 * Run: node --test tests/js/offline-phase4a.cjs
 */

const { describe, it, before, after, beforeEach } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');

const PROJECT_ROOT = 'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2';

// Helper to read file
function readSource(relativePath) {
  return fs.readFileSync(path.join(PROJECT_ROOT, relativePath), 'utf8');
}

describe('Phase 4A — Service Worker', () => {
  it('sw.js exists in public/', () => {
    const swPath = path.join(PROJECT_ROOT, 'public/sw.js');
    assert.ok(fs.existsSync(swPath), 'public/sw.js should exist');
  });

  it('sw.js has correct cache name with version', () => {
    const source = readSource('public/sw.js');
    assert.match(source, /const CACHE_NAME = 'mvs-pos-shell-v1'/, 'should have versioned cache name');
  });

  it('sw.js caches only shell assets (not entire app)', () => {
    const source = readSource('public/sw.js');
    assert.match(source, /const SHELL_ASSETS = \[/, 'should define SHELL_ASSETS array');
    assert.match(source, /'\/pos'/, 'should include POS route');
    assert.match(source, /'\/build\/assets\/app/, 'should include compiled CSS/JS');
  });

  it('sw.js does NOT cache API endpoints', () => {
    const source = readSource('public/sw.js');
    assert.match(source, /const NETWORK_ONLY_PATHS = \[/, 'should define NETWORK_ONLY_PATHS');
    assert.match(source, /'\/api\/'/, 'should exclude /api/');
    assert.match(source, /'\/mvs\/offline\/'/, 'should exclude /mvs/offline/');
    assert.match(source, /'\/pos\/productos\/'/, 'should exclude POS product search');
    assert.match(source, /'\/pos\/clientes\/'/, 'should exclude POS customer search');
    assert.match(source, /'\/pos\/cobrar'/, 'should exclude POS checkout');
  });

  it('sw.js uses cache-first for static assets', () => {
    const source = readSource('public/sw.js');
    assert.match(source, /function cacheFirst/, 'should have cacheFirst function');
    assert.match(source, /isStaticAsset/, 'should identify static assets');
  });

  it('sw.js uses network-first with fallback for POS navigation', () => {
    const source = readSource('public/sw.js');
    assert.match(source, /function networkFirstWithOfflineFallback/, 'should have networkFirstWithOfflineFallback');
    assert.match(source, /isPosNavigation/, 'should identify POS navigation');
  });

  it('sw.js uses network-only for APIs and POST', () => {
    const source = readSource('public/sw.js');
    assert.match(source, /isNetworkOnlyRequest/, 'should identify network-only requests');
    assert.match(source, /request\.method !== 'GET'/, 'should never cache POST');
  });

  it('sw.js activate cleans only MVS Offline caches', () => {
    const source = readSource('public/sw.js');
    assert.match(source, /name\.startsWith\('mvs-pos-shell-'\)/, 'should only delete mvs-pos-shell-* caches');
    assert.match(source, /name !== CACHE_NAME/, 'should not delete current cache');
  });

  it('sw.js does NOT cache private/enterprise data', () => {
    const source = readSource('public/sw.js');
    assert.match(source, /Does NOT cache dynamic enterprise data/, 'should have security comment');
    assert.match(source, /IndexedDB/, 'should reference IndexedDB as data source');
  });

  it('sw.js has version in CACHE_VERSION constant', () => {
    const source = readSource('public/sw.js');
    assert.match(source, /const CACHE_VERSION = 1/, 'should have CACHE_VERSION');
  });

  it('sw.js responds to getVersion message', () => {
    const source = readSource('public/sw.js');
    assert.match(source, /getVersion/, 'should handle getVersion message');
  });
});

describe('Phase 4A — Public Key Delivery', () => {
  it('OfflineAuthorizationController has publicKey method', () => {
    const source = readSource('app/Http/Controllers/OfflineAuthorizationController.php');
    assert.match(source, /public function publicKey/, 'should have publicKey method');
  });

  it('publicKey endpoint returns public key from service', () => {
    const source = readSource('app/Http/Controllers/OfflineAuthorizationController.php');
    assert.match(source, /\$this->authorizationService->getPublicKey\(\)/, 'should call service getPublicKey');
  });

  it('publicKey endpoint returns 503 if key not available', () => {
    const source = readSource('app/Http/Controllers/OfflineAuthorizationController.php');
    assert.match(source, /503/, 'should return 503 when key missing');
  });

  it('route for public key exists', () => {
    const source = readSource('routes/web.php');
    assert.match(source, /offline\.public-key/, 'should have named route offline.public-key');
    assert.match(source, /Route::get\('\/mvs\/offline\/public-key'/, 'should be GET endpoint');
  });

  it('OfflineAuthorizationService has getPublicKey method', () => {
    const source = readSource('app/Services/OfflineAuthorizationService.php');
    assert.match(source, /public function getPublicKey/, 'should have getPublicKey method');
  });

  it('getPublicKey loads from storage', () => {
    const source = readSource('app/Services/OfflineAuthorizationService.php');
    assert.match(source, /loadPublicKey/, 'should load from storage');
  });

  it('private key is NEVER exposed in publicKey endpoint', () => {
    const source = readSource('app/Http/Controllers/OfflineAuthorizationController.php');
    const privateKeyMethods = ['loadPrivateKey', 'privateKey', 'private_key'];
    for (const method of privateKeyMethods) {
      assert.ok(!source.includes(method), `should not reference ${method} in controller`);
    }
  });

  it('APP_KEY is NEVER exposed', () => {
    const controllerSource = readSource('app/Http/Controllers/OfflineAuthorizationController.php');
    const serviceSource = readSource('app/Services/OfflineAuthorizationService.php');
    assert.ok(!controllerSource.includes('APP_KEY'), 'controller should not expose APP_KEY');
    assert.ok(!serviceSource.includes('APP_KEY'), 'service should not expose APP_KEY');
  });
});

describe('Phase 4A — Offline Ready Check', () => {
  it('offline-ready.js exists', () => {
    const filePath = path.join(PROJECT_ROOT, 'resources/js/offline/offline-ready.js');
    assert.ok(fs.existsSync(filePath), 'offline-ready.js should exist');
  });

  it('offline-ready.js exports checkOfflineReady', () => {
    const source = readSource('resources/js/offline/offline-ready.js');
    assert.match(source, /export async function checkOfflineReady/, 'should export checkOfflineReady');
  });

  it('checkOfflineReady checks Service Worker', () => {
    const source = readSource('resources/js/offline/offline-ready.js');
    assert.match(source, /checkServiceWorkerReady/, 'should check Service Worker');
  });

  it('checkOfflineReady checks snapshot', () => {
    const source = readSource('resources/js/offline/offline-ready.js');
    assert.match(source, /checkSnapshotReady/, 'should check snapshot');
  });

  it('checkOfflineReady checks authorization', () => {
    const source = readSource('resources/js/offline/offline-ready.js');
    assert.match(source, /checkAuthorizationReady/, 'should check authorization');
  });

  it('checkOfflineReady checks public key', () => {
    const source = readSource('resources/js/offline/offline-ready.js');
    assert.match(source, /checkPublicKeyReady/, 'should check public key');
  });

  it('checkOfflineReady validates context match', () => {
    const source = readSource('resources/js/offline/offline-ready.js');
    assert.match(source, /company_id.*context/, 'should validate company match');
    assert.match(source, /branch_id.*context/, 'should validate branch match');
    assert.match(source, /terminal_uuid.*context/, 'should validate terminal match');
  });

  it('checkOfflineReady enforces 48h max window', () => {
    const source = readSource('resources/js/offline/offline-ready.js');
    assert.match(source, /48 \* 60 \* 60 \* 1000/, 'should enforce 48-hour max window');
  });

  it('offline-ready.js exports canMakeOfflineSale', () => {
    const source = readSource('resources/js/offline/offline-ready.js');
    assert.match(source, /export async function canMakeOfflineSale/, 'should export canMakeOfflineSale');
  });

  it('canMakeOfflineSale verifies auth token signature', () => {
    const source = readSource('resources/js/offline/offline-ready.js');
    assert.match(source, /verifyAuthSignature/, 'should verify auth token signature');
  });

  it('offline-ready.js stores public key in localStorage', () => {
    const source = readSource('resources/js/offline/offline-ready.js');
    assert.match(source, /localStorage\.setItem.*PUBLIC_KEY_STORAGE_KEY/, 'should store in localStorage');
    assert.match(source, /localStorage\.getItem.*PUBLIC_KEY_STORAGE_KEY/, 'should read from localStorage');
  });

  it('offline-ready.js reads public key from meta tag', () => {
    const source = readSource('resources/js/offline/offline-ready.js');
    assert.match(source, /mvsoffline-public-key/, 'should reference meta tag name');
    assert.match(source, /querySelector/, 'should use querySelector');
  });

  it('offline-ready.js can fetch public key from server', () => {
    const source = readSource('resources/js/offline/offline-ready.js');
    assert.match(source, /fetch\('\/mvs\/offline\/public-key'/, 'should fetch from server endpoint');
  });
});

describe('Phase 4A — POS View Integration', () => {
  it('POS view injects public key meta tag', () => {
    const source = readSource('resources/views/pos/index.blade.php');
    assert.match(source, /mvsoffline-public-key/, 'should inject public key meta tag');
  });

  it('POS view registers Service Worker', () => {
    const source = readSource('resources/views/pos/index.blade.php');
    assert.match(source, /navigator\.serviceWorker\.register\('\/sw\.js'/, 'should register /sw.js');
  });

  it('POS view registers SW on load', () => {
    const source = readSource('resources/views/pos/index.blade.php');
    assert.match(source, /window\.addEventListener\('load'/, 'should register on load');
  });

  it('POS view does NOT modify checkout logic', () => {
    const source = readSource('resources/views/pos/index.blade.php');
    // Check that confirmCheckout still posts to server
    assert.match(source, /pos\.checkout/, 'should still use online checkout route');
    assert.match(source, /method: 'POST'/, 'should still POST checkout');
  });
});

describe('Phase 4A — Module Exports', () => {
  it('offline/index.js exports offline-ready functions', () => {
    const source = readSource('resources/js/offline/index.js');
    assert.match(source, /checkOfflineReady/, 'should export checkOfflineReady');
    assert.match(source, /canMakeOfflineSale/, 'should export canMakeOfflineSale');
    assert.match(source, /checkServiceWorkerReady/, 'should export checkServiceWorkerReady');
    assert.match(source, /checkSnapshotReady/, 'should export checkSnapshotReady');
    assert.match(source, /checkAuthorizationReady/, 'should export checkAuthorizationReady');
    assert.match(source, /checkPublicKeyReady/, 'should export checkPublicKeyReady');
    assert.match(source, /getPublicKey/, 'should export getPublicKey');
    assert.match(source, /storePublicKeyLocally/, 'should export storePublicKeyLocally');
    assert.match(source, /clearStoredPublicKey/, 'should export clearStoredPublicKey');
  });
});