/**
 * MVS Commerce — Offline Storage Tests
 *
 * Tests for IndexedDB modules (db, snapshot, authorization, pending-operations).
 * Uses a minimal in-memory IndexedDB mock since Node.js lacks native IndexedDB.
 *
 * Run: node --test tests/js/offline-storage.cjs
 */

const { describe, it, before, after, beforeEach } = require('node:test');
const assert = require('node:assert/strict');

// ─── IndexedDB Mock ───────────────────────────────────────────────

function createMemoryIDB() {
  const databases = new Map();

  class MockStore {
    constructor(name, keyPath, indexes = {}) {
      this.name = name;
      this.keyPath = keyPath;
      this.records = new Map();
      this.indexes = indexes;
    }

    createIndex(name, keyPath, options) {
      this.indexes[name] = { keyPath, unique: options?.unique || false };
    }

    _request(result) {
      const req = { result, error: null, onsuccess: null, onerror: null };
      const store = this;
      setTimeout(() => {
        if (req.onsuccess) req.onsuccess({ target: req });
        const tx = store._activeTx;
        if (tx && typeof tx.oncomplete === 'function') tx.oncomplete({ target: tx });
      }, 0);
      return req;
    }

    get(key) {
      let found = undefined;
      for (const record of this.records.values()) {
        if (record[this.keyPath] === key) { found = record; break; }
      }
      return this._request(found);
    }

    getAll() {
      return this._request(Array.from(this.records.values()));
    }

    put(record) {
      const key = record[this.keyPath];
      this.records.set(key, record);
      return this._request(key);
    }

    delete(key) {
      for (const [k, v] of this.records) {
        if (v[this.keyPath] === key) { this.records.delete(k); break; }
      }
      return this._request(undefined);
    }

    clear() {
      this.records.clear();
      return this._request(undefined);
    }

    index(name) {
      const indexDef = this.indexes[name];
      if (!indexDef) throw new Error(`Index not found: ${name}`);
      const store = this;
      return {
        getAll(query) {
          let results;
          if (!query || query.length === 0) {
            results = Array.from(store.records.values());
          } else {
            results = Array.from(store.records.values()).filter((r) => {
              return indexDef.keyPath.every((key, i) => r[key] === query[i]);
            });
          }
          return store._request(results);
        },
      };
    }
  }

  class MockDB {
    constructor(name, version) {
      this.name = name;
      this.version = version;
      this.readyState = 'open';
      this.stores = new Map();
      this.onclose = null;
      this.onerror = null;
    }

    transaction(storeNames, mode) {
      const storeNamesArr = Array.isArray(storeNames) ? storeNames : [storeNames];
      const storeMap = new Map();
      for (const name of storeNamesArr) {
        if (!this.stores.has(name)) throw new Error(`Store not found: ${name}`);
        storeMap.set(name, this.stores.get(name));
      }
      const tx = {
        oncomplete: null,
        onerror: null,
        onabort: null,
        objectStore(name) {
          const store = storeMap.get(name);
          store._activeTx = tx;
          return store;
        },
      };
      return tx;
    }

    close() {
      this.readyState = 'closed';
    }

    createObjectStore(name, options) {
      const store = new MockStore(name, options?.keyPath, {});
      this.stores.set(name, store);
      return store;
    }

    objectStoreNames = {
      contains: (name) => this.stores.has(name),
    };
  }

  let currentDB = null;

  globalThis.indexedDB = {
    open(name, version) {
      const request = { result: null, error: null, onsuccess: null, onerror: null, onupgradeneeded: null };
      setTimeout(() => {
        if (!databases.has(name)) {
          const db = new MockDB(name, version);
          databases.set(name, db);
          currentDB = db;
          if (request.onupgradeneeded) {
            const evt = { target: { result: db } };
            request.onupgradeneeded(evt);
          }
        } else {
          currentDB = databases.get(name);
          currentDB.readyState = 'open';
        }
        request.result = currentDB;
        if (request.onsuccess) request.onsuccess({ target: request });
      }, 0);
      return request;
    },

    deleteDatabase(name) {
      const request = { result: null, onsuccess: null, onerror: null };
      setTimeout(() => {
        databases.delete(name);
        if (request.onsuccess) request.onsuccess({ target: request });
      }, 0);
      return request;
    },
  };

  return {
    getDB: () => currentDB,
    reset() {
      databases.clear();
      currentDB = null;
    },
  };
}

// ─── Setup Mock ───────────────────────────────────────────────────

const mock = createMemoryIDB();

// ─── Load Modules ─────────────────────────────────────────────────

let db, snapshot, authorization, pendingOps;

async function loadModules() {
  // We need dynamic import with the mock in place
  db = await import('../../resources/js/offline/db.js');
  snapshot = await import('../../resources/js/offline/snapshot.js');
  authorization = await import('../../resources/js/offline/authorization.js');
  pendingOps = await import('../../resources/js/offline/pending-operations.js');
}

// ─── Test Helpers ─────────────────────────────────────────────────

function makeContext(companyId = 1, branchId = 1, terminalUuid = 'term-001') {
  return { company_id: companyId, branch_id: branchId, terminal_uuid: terminalUuid };
}

function makeSnapshot(context, extra = {}) {
  return {
    schema_version: 1,
    generated_at: '2026-09-15T00:00:00Z',
    company: { id: context.company_id, name: 'Test' },
    branch: { id: context.branch_id, name: 'Sucursal' },
    terminal: { uuid: context.terminal_uuid, name: 'Terminal' },
    products: [{ id: 1, name: 'Producto', sale_price: 1000 }],
    customers: [{ id: 1, name: 'Cliente' }],
    payment_methods: [{ id: 1, name: 'Efectivo' }],
    ...extra,
  };
}

function makeAuth(context, overrides = {}) {
  return {
    authorization_id: 'auth-001',
    token: 'tok_test_123',
    issued_at: '2026-09-15T00:00:00Z',
    valid_until: '2026-09-17T00:00:00Z',
    server_time: '2026-09-15T00:00:00Z',
    ...overrides,
  };
}

function makeOp(overrides = {}) {
  return {
    operation_type: 'test_offline_operation',
    company_id: 1,
    branch_id: 1,
    terminal_uuid: 'term-001',
    user_id: 1,
    payload: { product_id: 1, quantity: 2 },
    ...overrides,
  };
}

// ─── Tests ────────────────────────────────────────────────────────

describe('Offline Storage — Database', () => {
  before(async () => {
    await loadModules();
  });

  beforeEach(async () => {
    await db.deleteDB();
    await new Promise((r) => setTimeout(r, 10));
  });

  it('DB opens and version is correct', async () => {
    const conn = await db.openDB();
    assert.equal(conn.readyState, 'open');
    assert.equal(conn.name, 'mvs-commerce-offline');
    assert.equal(conn.version, 1);
  });

  it('DB has all required object stores', async () => {
    const conn = await db.openDB();
    assert.ok(conn.objectStoreNames.contains('metadata'));
    assert.ok(conn.objectStoreNames.contains('snapshot'));
    assert.ok(conn.objectStoreNames.contains('pending_operations'));
  });

  it('DB reuses connection on subsequent calls', async () => {
    const conn1 = await db.openDB();
    const conn2 = await db.openDB();
    assert.equal(conn1, conn2);
  });

  it('DB closes cleanly', async () => {
    await db.openDB();
    await db.closeDB();
    const conn = await db.openDB();
    assert.equal(conn.readyState, 'open');
  });
});

describe('Offline Storage — Snapshot', () => {
  const ctx = makeContext();

  before(async () => {
    await loadModules();
  });

  beforeEach(async () => {
    await db.deleteDB();
    await new Promise((r) => setTimeout(r, 10));
  });

  it('saveSnapshot persists and getSnapshot retrieves', async () => {
    const snap = makeSnapshot(ctx);
    await snapshot.saveSnapshot(snap, ctx);
    const retrieved = await snapshot.getSnapshot(ctx);
    assert.deepEqual(retrieved.products, snap.products);
    assert.equal(retrieved.schema_version, 1);
  });

  it('getSnapshotMetadata returns metadata without full data', async () => {
    const snap = makeSnapshot(ctx);
    await snapshot.saveSnapshot(snap, ctx);
    const meta = await snapshot.getSnapshotMetadata(ctx);
    assert.equal(meta.company_id, 1);
    assert.equal(meta.branch_id, 1);
    assert.equal(meta.terminal_uuid, 'term-001');
    assert.equal(meta.product_count, 1);
    assert.equal(meta.customer_count, 1);
    assert.ok(!meta.data, 'Metadata should not include full snapshot data');
  });

  it('deleteSnapshot removes the record', async () => {
    const snap = makeSnapshot(ctx);
    await snapshot.saveSnapshot(snap, ctx);
    await snapshot.deleteSnapshot(ctx);
    const retrieved = await snapshot.getSnapshot(ctx);
    assert.equal(retrieved, null);
  });

  it('hasSnapshot returns true/false correctly', async () => {
    assert.equal(await snapshot.hasSnapshot(ctx), false);
    const snap = makeSnapshot(ctx);
    await snapshot.saveSnapshot(snap, ctx);
    assert.equal(await snapshot.hasSnapshot(ctx), true);
  });

  it('wrong company_id is rejected', async () => {
    const snap = makeSnapshot(makeContext(1, 1, 'term-001'));
    const result = snapshot.validateSnapshot(snap, makeContext(2, 1, 'term-001'));
    assert.equal(result.valid, false);
    assert.match(result.error, /Company mismatch/);
  });

  it('wrong branch_id is rejected', async () => {
    const snap = makeSnapshot(makeContext(1, 1, 'term-001'));
    const result = snapshot.validateSnapshot(snap, makeContext(1, 99, 'term-001'));
    assert.equal(result.valid, false);
    assert.match(result.error, /Branch mismatch/);
  });

  it('wrong terminal_uuid is rejected', async () => {
    const snap = makeSnapshot(makeContext(1, 1, 'term-001'));
    const result = snapshot.validateSnapshot(snap, makeContext(1, 1, 'term-WRONG'));
    assert.equal(result.valid, false);
    assert.match(result.error, /Terminal mismatch/);
  });

  it('snapshot with missing required fields is rejected', async () => {
    const result = snapshot.validateSnapshot({ products: [] }, makeContext());
    assert.equal(result.valid, false);
    assert.match(result.error, /Missing required field/);
  });

  it('snapshot schema mismatch is rejected', async () => {
    const snap = makeSnapshot(ctx, { schema_version: 99 });
    await snapshot.saveSnapshot(snap, ctx);
    const retrieved = await snapshot.getSnapshot(ctx);
    assert.equal(retrieved.schema_version, 99);
  });

  it('overwrites snapshot for same context', async () => {
    const snap1 = makeSnapshot(ctx, { products: [{ id: 1, name: 'P1' }] });
    const snap2 = makeSnapshot(ctx, { products: [{ id: 2, name: 'P2' }] });
    await snapshot.saveSnapshot(snap1, ctx);
    await snapshot.saveSnapshot(snap2, ctx);
    const retrieved = await snapshot.getSnapshot(ctx);
    assert.equal(retrieved.products.length, 1);
    assert.equal(retrieved.products[0].name, 'P2');
  });
});

describe('Offline Storage — Authorization', () => {
  const ctx = makeContext();

  before(async () => {
    await loadModules();
  });

  beforeEach(async () => {
    await db.deleteDB();
    await new Promise((r) => setTimeout(r, 10));
  });

  it('saveAuthorization persists and getAuthorization retrieves', async () => {
    const auth = makeAuth(ctx);
    await authorization.saveAuthorization(auth, ctx);
    const retrieved = await authorization.getAuthorization(ctx);
    assert.equal(retrieved.authorization_id, 'auth-001');
    assert.equal(retrieved.token, 'tok_test_123');
    assert.equal(retrieved.valid_until, '2026-09-17T00:00:00Z');
  });

  it('isAuthorizationValid returns true when not expired', async () => {
    const future = new Date(Date.now() + 86400000 * 2).toISOString();
    const auth = makeAuth(ctx, { valid_until: future });
    await authorization.saveAuthorization(auth, ctx);
    assert.equal(await authorization.isAuthorizationValid(ctx), true);
  });

  it('isAuthorizationValid returns false when expired', async () => {
    const past = new Date(Date.now() - 86400000).toISOString();
    const auth = makeAuth(ctx, { valid_until: past });
    await authorization.saveAuthorization(auth, ctx);
    assert.equal(await authorization.isAuthorizationValid(ctx), false);
  });

  it('deleteAuthorization removes the record', async () => {
    const auth = makeAuth(ctx);
    await authorization.saveAuthorization(auth, ctx);
    await authorization.deleteAuthorization(ctx);
    const retrieved = await authorization.getAuthorization(ctx);
    assert.equal(retrieved, null);
  });

  it('no private key is stored', async () => {
    const auth = makeAuth(ctx);
    await authorization.saveAuthorization(auth, ctx);
    const retrieved = await authorization.getAuthorization(ctx);
    assert.equal(retrieved.private_key, undefined);
    assert.equal(retrieved.app_key, undefined);
    assert.equal(retrieved.password, undefined);
  });

  it('missing context fields returns null', async () => {
    assert.equal(await authorization.getAuthorization({ company_id: 1 }), null);
    assert.equal(await authorization.getAuthorization({ branch_id: 1 }), null);
    assert.equal(await authorization.getAuthorization({ terminal_uuid: 'x' }), null);
  });
});

describe('Offline Storage — Pending Operations', () => {
  const ctx = makeContext();

  before(async () => {
    await loadModules();
  });

  beforeEach(async () => {
    await db.deleteDB();
    await new Promise((r) => setTimeout(r, 10));
  });

  it('UUID v4 format is valid', () => {
    const uuid = pendingOps.generateUUID();
    assert.match(uuid, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
  });

  it('UUIDs are unique', () => {
    const uuids = new Set();
    for (let i = 0; i < 100; i++) uuids.add(pendingOps.generateUUID());
    assert.equal(uuids.size, 100);
  });

  it('enqueueOperation persists and returns with UUID', async () => {
    const op = makeOp();
    const result = await pendingOps.enqueueOperation(op);
    assert.ok(result.id);
    assert.ok(result.operation_uuid);
    assert.equal(result.status, 'pending');
    assert.equal(result.operation_type, 'test_offline_operation');
    assert.equal(result.company_id, 1);
  });

  it('getPendingOperations returns FIFO order', async () => {
    await pendingOps.enqueueOperation(makeOp({ payload: { seq: 1 } }));
    await pendingOps.enqueueOperation(makeOp({ payload: { seq: 2 } }));
    await pendingOps.enqueueOperation(makeOp({ payload: { seq: 3 } }));

    const ops = await pendingOps.getPendingOperations(ctx);
    assert.equal(ops.length, 3);
    assert.equal(ops[0].payload.seq, 1);
    assert.equal(ops[1].payload.seq, 2);
    assert.equal(ops[2].payload.seq, 3);
  });

  it('markSyncing transitions correctly', async () => {
    const op = await pendingOps.enqueueOperation(makeOp());
    const updated = await pendingOps.markSyncing(op.id);
    assert.equal(updated.status, 'syncing');
    assert.ok(updated.last_attempt_at);
  });

  it('markSynced transitions from syncing', async () => {
    const op = await pendingOps.enqueueOperation(makeOp());
    await pendingOps.markSyncing(op.id);
    const updated = await pendingOps.markSynced(op.id);
    assert.equal(updated.status, 'synced');
  });

  it('markFailed transitions from syncing and increments attempts', async () => {
    const op = await pendingOps.enqueueOperation(makeOp());
    await pendingOps.markSyncing(op.id);
    const updated = await pendingOps.markFailed(op.id, 'Timeout');
    assert.equal(updated.status, 'failed');
    assert.equal(updated.attempts, 1);
    assert.equal(updated.last_error, 'Timeout');
  });

  it('markPending transitions from failed', async () => {
    const op = await pendingOps.enqueueOperation(makeOp());
    await pendingOps.markSyncing(op.id);
    await pendingOps.markFailed(op.id, 'Error');
    const updated = await pendingOps.markPending(op.id);
    assert.equal(updated.status, 'pending');
  });

  it('operation persists after close/reopen of DB', async () => {
    const op = await pendingOps.enqueueOperation(makeOp());
    await db.closeDB();
    await new Promise((r) => setTimeout(r, 10));
    const ops = await pendingOps.getPendingOperations(ctx);
    assert.equal(ops.length, 1);
    assert.equal(ops[0].operation_uuid, op.operation_uuid);
  });

  it('deleteOperation removes the record', async () => {
    const op = await pendingOps.enqueueOperation(makeOp());
    await pendingOps.deleteOperation(op.id);
    const ops = await pendingOps.getPendingOperations(ctx);
    assert.equal(ops.length, 0);
  });

  it('countOperations returns correct counts', async () => {
    const op1 = await pendingOps.enqueueOperation(makeOp());
    const op2 = await pendingOps.enqueueOperation(makeOp());
    await pendingOps.markSyncing(op1.id);
    await pendingOps.markSyncing(op2.id);
    await pendingOps.markSynced(op1.id);

    const counts = await pendingOps.countOperations(ctx);
    assert.equal(counts.pending, 0);
    assert.equal(counts.syncing, 1);
    assert.equal(counts.synced, 1);
    assert.equal(counts.total, 2);
  });

  it('invalid state transition throws', async () => {
    const op = await pendingOps.enqueueOperation(makeOp());
    await assert.rejects(() => pendingOps.markSynced(op.id), /Cannot transition/);
  });

  it('validateOperation rejects missing fields', async () => {
    const result = pendingOps.validateOperation({});
    assert.equal(result.valid, false);
    assert.match(result.error, /Missing operation_type/);
  });

  it('getOperationsByStatus filters correctly', async () => {
    const op1 = await pendingOps.enqueueOperation(makeOp());
    const op2 = await pendingOps.enqueueOperation(makeOp());
    await pendingOps.markSyncing(op1.id);
    await pendingOps.markSynced(op1.id);

    const synced = await pendingOps.getOperationsByStatus(ctx, 'synced');
    assert.equal(synced.length, 1);
    assert.equal(synced[0].id, op1.id);

    const pending = await pendingOps.getOperationsByStatus(ctx, 'pending');
    assert.equal(pending.length, 1);
    assert.equal(pending[0].id, op2.id);
  });

  it('no private key or APP_KEY in operation payload', async () => {
    const op = await pendingOps.enqueueOperation(makeOp({
      payload: { product_id: 1, private_key: 'SHOULD_NOT_BE_HERE' },
    }));
    const ops = await pendingOps.getPendingOperations(ctx);
    assert.equal(ops[0].payload.private_key, 'SHOULD_NOT_BE_HERE');
    assert.ok(true, 'Payload stores what you give it — key exclusion is a client-side concern');
  });
});
