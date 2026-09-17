/**
 * MVS Commerce — Offline Phase 4B Tests
 *
 * Tests for: FIFO sync worker, idempotent server ACK, backpressure,
 * transient vs permanent error handling, canonical v1 sale payload.
 *
 * Run: node --test tests/js/offline-phase4b.cjs
 */

const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');

const PROJECT_ROOT = 'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2';

function readSource(relativePath) {
  return fs.readFileSync(path.join(PROJECT_ROOT, relativePath), 'utf8');
}

describe('Phase 4B — Sync worker module', () => {
  it('sync-worker.js exists', () => {
    assert.ok(fs.existsSync(path.join(PROJECT_ROOT, 'resources/js/offline/sync-worker.js')));
  });

  it('enforces MAX_CONCURRENT = 1 (strict FIFO, no batches)', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /const MAX_CONCURRENT = 1/, 'MAX_CONCURRENT must be exactly 1');
    assert.doesNotMatch(source, /Promise\.all\(/, 'must never batch operations (no Promise.all call)');
    assert.match(source, /for \(const op of pending\)/, 'must process strictly sequentially');
  });

  it('defines the required public exports', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    for (const symbol of [
      'syncPendingOperations',
      'isSyncRunning',
      'getBackoffMs',
      'resetBackoff',
      'stopSync',
    ]) {
      assert.ok(source.includes(`export function ${symbol}`) || source.includes(`export async function ${symbol}`), `should export ${symbol}`);
    }
    const tail = source.split('export {').pop();
    assert.match(tail, /MAX_CONCURRENT/);
    assert.match(tail, /PAUSE_BETWEEN_OPERATIONS_MS/);
    assert.match(tail, /SYNC_URL/);
  });

  it('uses the correct server sync endpoint', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /const SYNC_URL = '\/mvs\/offline\/sync'/, 'SYNC_URL must match the route');
  });

  it('applies a backpressure pause between consecutive operations', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /const PAUSE_BETWEEN_OPERATIONS_MS = 300/, 'should pause 300ms between operations');
    assert.match(source, /await sleep\(PAUSE_BETWEEN_OPERATIONS_MS\)/, 'worker must actually await the pause');
  });

  it('has a retry backoff sequence from 2s to 60s', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /const BASE_BACKOFF_MS = 2000/);
    assert.match(source, /const MAX_BACKOFF_MS = 60000/);
  });

  it('acquires a sync lock so two runs never race', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /if \(running\)/, 'should guard against a second run');
    assert.match(source, /locked: true/, 'should report the run as locked');
    assert.match(source, /running = true/);
    assert.match(source, /running = false/);
  });

  it('processes operations in FIFO order (oldest created_at_local first)', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /filter\(\(op\) => op\.status === 'pending'\)/, 'should only pick pending operations');
    assert.match(
      source,
      /sort\(\(a, b\) => new Date\(a\.created_at_local\) - new Date\(b\.created_at_local\)\)/,
      'should sort by creation time ascending',
    );
  });

  it('marks the operation as syncing before the POST and synced on server ACK', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /await markSyncing\(op\.id\)/, 'should mark syncing before the request');
    assert.match(source, /bodyStatus === 'processed' \|\| bodyStatus === 'already_processed'/, 'should accept idempotent ACK');
    assert.match(source, /await markSynced\(op\.id(?:,|\))/, 'should mark synced after ACK');
  });

  it('reads the CSRF token from the meta tag', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /meta\[name="csrf-token"\]/, 'should read CSRF from meta tag');
    assert.match(source, /X-CSRF-TOKEN/, 'should send X-CSRF-TOKEN header');
  });

  it('requests JSON responses explicitly', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /Accept: 'application\/json'/, 'should set Accept: application/json');
    assert.match(source, /['"]X-Requested-With['"]:\s*'XMLHttpRequest'/, 'should mark the request as XHR');
    assert.match(source, /credentials: 'same-origin'/, 'should keep same-origin credentials');
  });

  it('classifies responses: auth / conflict / validation / transient', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /if \(status === 401 \|\| status === 403\) return 'auth'/, '401/403 -> auth');
    assert.match(source, /if \(status === 409\) return 'conflict'/, '409 -> conflict');
    assert.match(source, /if \(status === 400 \|\| status === 422\) return 'validation'/, '400/422 -> validation');
    assert.match(source, /if \(status === 408 \|\| status >= 500\) return 'transient'/, '408/5xx -> transient');
  });

  it('treats 409/422 as permanent and preserves the operation, never deletes it', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /kind === 'conflict' \|\| kind === 'validation'/, 'should handle permanent errors');
    assert.match(source, /await markFailed\(op\.id, await readMessage\(response\)\)/, 'should mark failed with server reason');
    assert.doesNotMatch(source, /deleteOperation\(op\.id\)/, 'must never delete a failed operation');
  });

  it('treats network errors and 5xx as transient and requeueable', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /await markTransientError\(op\.id, 'Network error: no response from server\.'\)/, 'network error -> transient');
    assert.match(source, /Error transitorio del servidor \(HTTP/, '5xx -> transient message');
    assert.match(source, /transientHit = true/, 'should stop the run after a transient error');
  });

  it('stops the whole sync when the authorization is revoked (401/403)', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /kind === 'auth'/, 'should handle auth category');
    assert.match(source, /summary\.reason = 'auth_revoked'/, 'should flag auth_revoked');
  });

  it('requeues operations reported as in_progress', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /bodyStatus === 'in_progress'/, 'should handle in_progress ACK');
    assert.match(source, /Operaci\u00f3n ya en proceso\. Reintentando m\u00e1s tarde\./, 'should keep it requeueable');
  });

  it('checks connectivity before each run and resets backoff when idle', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /checkServerReachable\(\)/, 'should require server reachability');
    assert.match(source, /resetBackoff\(\)/, 'should reset backoff when idle');
    assert.match(source, /if \(reachable !== 'online'\)/, 'should stop when offline');
  });

  it('caps a single run to avoid indefinite background processing', () => {
    const source = readSource('resources/js/offline/sync-worker.js');
    assert.match(source, /MAX_SINGLE_RUN_MS/, 'should limit single run duration');
    assert.match(source, /Date\.now\(\) - startedAt < MAX_SINGLE_RUN_MS/, 'should cap the run');
  });
});

describe('Phase 4B — Pending operations queue', () => {
  it('exports markTransientError for requeueable failures', () => {
    const source = readSource('resources/js/offline/pending-operations.js');
    assert.match(source, /export async function markTransientError/, 'should export markTransientError');
  });

  it('markTransientError goes back to pending, increments attempts and stores last_error', () => {
    const source = readSource('resources/js/offline/pending-operations.js');
    assert.match(
      source,
      /attempts: \(op\.attempts \|\| 0\) \+ 1/,
      'should increment attempts',
    );
    assert.match(source, /last_error: errorMessage \|\| 'Transient error'/, 'should store error reason');
    assert.match(source, /last_attempt_at: new Date\(\)\.toISOString\(\)/, 'should stamp last attempt');
  });

  it('allows the syncing -> pending transition required by transient retries', () => {
    const source = readSource('resources/js/offline/pending-operations.js');
    assert.match(source, /syncing: \['synced', 'failed', 'pending'\]/, 'VALID_TRANSITIONS must allow pending from syncing');
  });

  it('a failed operation can be retried (failed -> pending)', () => {
    const source = readSource('resources/js/offline/pending-operations.js');
    assert.match(source, /failed: \['pending'\]/, 'failed should remain requeueable');
  });
});

describe('Phase 4B — Canonical sale payload (v1)', () => {
  it('sale.js exports buildSalePayload with payload_version 1', () => {
    const source = readSource('resources/js/offline/sale.js');
    assert.match(source, /export function buildSalePayload/, 'should export buildSalePayload');
    assert.match(source, /payload_version: 1/, 'should stamp payload_version: 1');
  });

  it('maps the legacy document type factura -> invoice and defaults to ticket', () => {
    const source = readSource('resources/js/offline/sale.js');
    assert.match(source, /if \(normalized === 'invoice' \|\| normalized === 'factura'\)/, 'should map invoice/factura');
    assert.match(source, /return 'ticket';/, 'should default to ticket');
  });

  it('emits the canonical item and payment shapes the server expects', () => {
    const source = readSource('resources/js/offline/sale.js');
    assert.match(source, /product_id: item\.productId/, 'items must carry product_id');
    assert.match(source, /quantity: toDecimalString\(item\.quantity\)/, 'quantity must be a decimal string');
    assert.match(source, /payment_method_id/, 'payments must carry payment_method_id');
    assert.match(source, /terminal_uuid: terminalContext\.terminal_uuid/, 'payload must carry terminal_uuid');
    assert.match(source, /created_at_local: new Date\(\)\.toISOString\(\)/, 'payload must carry created_at_local');
  });

  it('does not embed a client UUID in the payload (server owns idempotency via operation_uuid)', () => {
    const source = readSource('resources/js/offline/sale.js');
    assert.doesNotMatch(source, /generateUUID/, 'sale.js must not generate UUIDs for the payload');
    assert.doesNotMatch(source, /Math\.random/, 'must never use Math.random');
  });
});

describe('Phase 4B — Module entry point and route', () => {
  it('index.js re-exports the sync worker public API', () => {
    const source = readSource('resources/js/offline/index.js');
    for (const symbol of [
      'syncPendingOperations',
      'isSyncRunning',
      'getBackoffMs',
      'resetBackoff',
      'stopSync',
      'MAX_CONCURRENT',
      'PAUSE_BETWEEN_OPERATIONS_MS',
      'SYNC_URL',
    ]) {
      assert.ok(source.includes(symbol), `index.js should re-export ${symbol}`);
    }
    assert.match(source, /from '\.\/sync-worker\.js'/, 'should import from sync-worker.js');
  });

  it('registers POST /mvs/offline/sync with permission ventas.crear', () => {
    const source = readSource('routes/web.php');
    assert.match(source, /Route::post\('\/mvs\/offline\/sync'/, 'should define the sync route');
    assert.match(source, /OfflineSyncController::class, 'sync'/, 'should point to the sync controller');
    assert.match(source, /->middleware\('permission:ventas\.crear'\)/, 'should require ventas.crear');
    assert.match(source, /->name\('offline\.sync'\);/, 'should be named offline.sync');
  });

  it('the sync route lives inside the licensed tenant group', () => {
    const source = readSource('routes/web.php');
    const syncIndex = source.indexOf("Route::post('/mvs/offline/sync'");
    const group = source.slice(0, syncIndex);
    assert.ok(
      group.includes("Route::middleware(['auth', 'active.company', 'company.licensed'])"),
      'offline routes must be inside the tenant licensed group',
    );
  });
});

describe('Phase 4B — Service-level contract (server side)', () => {
  it('OfflineSyncService reuses the official PosSaleProcessor', () => {
    const source = readSource('app/Services/OfflineSyncService.php');
    assert.match(source, /PosSaleProcessor/, 'should use the official processor');
    assert.match(source, /->process\(/, 'should call process(...)');
    assert.match(source, /\$processed\['sale'\]/, 'should map the processed sale');
    assert.match(source, /\$processed\['duplicate'\]/, 'should honour the duplicate flag');
  });

  it('idempotency uses the UNIQUE(operation_uuid) race + payload hash, not a plain exists check', () => {
    const source = readSource('app/Services/OfflineSyncService.php');
    assert.match(source, /payloadHash\(/, 'should compute a server-side payload hash');
    assert.match(source, /hash_equals/, 'should compare hashes with constant time');
    assert.match(source, /ConflictHttpException/, 'should throw a conflict on hash mismatch');
    assert.match(source, /isUniqueViolation/, 'should detect UNIQUE violations');
    assert.match(source, /hash\('sha256'/, 'should hash with sha256');
  });

  it('late sync is allowed with an expired token but only inside the authorization window', () => {
    const source = readSource('app/Services/OfflineSyncService.php');
    assert.match(source, /verifyTokenAllowExpired/, 'should verify expired tokens');
    assert.match(source, /assertCreationWithinAuthorizationWindow/, 'should enforce the creation window');
  });

  it('the controller returns JSON 202/409/422/503 without relying on the Accept header', () => {
    const source = readSource('app/Http/Controllers/OfflineSyncController.php');
    assert.match(source, /return response\(\)->json\(/, 'controller must return JSON');
    assert.match(source, /, 409\)/, 'must return 409 for conflicts');
    assert.match(source, /, 422\)/, 'must return 422 for validation');
    assert.match(source, /, 503\)/, 'must return 503 for database hiccups');
    assert.match(source, /, 202\)/, 'must return 202 for in_progress');
  });
});
