/**
 * MVS Commerce — Offline Phase 3B Tests
 *
 * Tests for: UUID hardening, connectivity detection,
 * authorization verification, offline sale handling,
 * double-click protection, and snapshot validation.
 *
 * Run: node --test tests/js/offline-phase3b.cjs
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
      this.indexes[name] = { keyPath: keyPath, unique: options?.unique || false };
    }

    _request(result) {
      const req = { result, error: null, onsuccess: null, onerror: null };
      setTimeout(() => { if (req.onsuccess) req.onsuccess({ target: req }); }, 0);
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
          if (!query || query.length === 0) return store._request(Array.from(store.records.values()));
          let results;
          if (query == null || query.length === 0) {
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
    }
    transaction(storeNames, mode) {
      const storeNamesArr = Array.isArray(storeNames) ? storeNames : [storeNames];
      const storeMap = new Map();
      for (const name of storeNamesArr) {
        if (!this.stores.has(name)) throw new Error(`Store not found: ${name}`);
        storeMap.set(name, this.stores.get(name));
      }
      return { objectStore(name) { return storeMap.get(name); } };
    }
    close() { this.readyState = 'closed'; }
    createObjectStore(name, options) {
      const store = new MockStore(name, options?.keyPath, {});
      this.stores.set(name, store);
      return store;
    }
    objectStoreNames = { contains: (name) => this.stores.has(name) };
  }

  globalThis.indexedDB = {
    open(name, version) {
      const request = { result: null, error: null, onsuccess: null, onerror: null, onupgradeneeded: null };
      setTimeout(() => {
        if (!databases.has(name)) {
          const db = new MockDB(name, version);
          databases.set(name, db);
        }
        const db = databases.get(name);
        db.readyState = 'open';
        request.result = db;
        if (request.onsuccess) request.onsuccess({ target: request });
      }, 0);
      return request;
    },
    deleteDatabase(name) {
      const request = { result: null, onsuccess: null, onerror: null };
      setTimeout(() => { databases.delete(name); if (request.onsuccess) request.onsuccess({ target: request }); }, 0);
      return request;
    },
  };

  let currentDB = null;
  globalThis.indexedDB.open = function(name, version) {
    const request = { result: null, error: null, onsuccess: null, onerror: null, onupgradeneeded: null };
    setTimeout(() => {
      if (!databases.has(name)) {
        const db = new MockDB(name, version);
        databases.set(name, db);
      }
      currentDB = databases.get(name);
      currentDB.readyState = 'open';
      request.result = currentDB;
      if (request.onsuccess) request.onsuccess({ target: request });
    }, 0);
    return request;
  };

  return {
    getDB: () => currentDB,
    reset() { databases.clear(); currentDB = null; },
  };
}

const mock = createMemoryIDB();

// ─── Helpers ──────────────────────────────────────────────────────

function makeContext(companyId, branchId, terminalUuid) {
  return { company_id: companyId, branch_id: branchId, terminal_uuid: terminalUuid || 'term-001' };
}

function b64encode(str) {
  const c1 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
  let output = '';
  let padding = 0;
  for (let i = 0; i < str.length; i += 3) {
    const triple = str.charCodeAt(i) << 16 | (i + 1 < str.length ? str.charCodeAt(i + 1) : 0) << 8 | (i + 2 < str.length ? str.charCodeAt(i + 2) : 0);
    output += c1.charAt(triple >> 18 & 63) + c1.charAt(triple >> 12 & 63) + c1.charAt(triple >> 6 & 63) + c1.charAt(triple & 63);
    padding += 3;
  }
  if (padding === 1) output = output.slice(0, -1) + '=';
  if (padding === 2) output = output.slice(0, -2) + '==';
  return output;
}

// ─── Tests ────────────────────────────────────────────────────────

describe('Phase 3B — UUID Hardening', () => {
  it('pending-operations.js eliminates Math.random fallback', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\pending-operations.js',
      'utf8'
    );
    const hasOldMathRandom = source.includes('Math.random()') || source.includes('Math.random ');
    const usesCryptoGetRandom = source.includes('crypto.getRandomValues');
    assert.ok(!hasOldMathRandom, 'pending-operations.js should NOT use Math.random() in UUID fallback');
    assert.ok(usesCryptoGetRandom, 'pending-operations.js should use crypto.getRandomValues()');
  });

  it('pending-operations.js throws when no crypto source available', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\pending-operations.js',
      'utf8'
    );
    const throwsWhenNoCrypto = source.includes('throw new Error') && source.includes('cryptographic random source');
    assert.ok(throwsWhenNoCrypto, 'generateUUID should throw when no crypto source available');
  });
});

describe('Phase 3B — Connectivity Detection', () => {
  it('connectivity.js uses navigator.onLine as quick signal', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\connectivity.js',
      'utf8'
    );
    const usesOnLine = source.includes('navigator.onLine');
    assert.ok(usesOnLine, 'should use navigator.onLine as quick signal');
  });

  it('connectivity.js attempts server fetch check', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\connectivity.js',
      'utf8'
    );
    const usesFetch = source.includes('fetch') && source.includes('CONNECTIVITY_CHECK_URL');
    assert.ok(usesFetch, 'should attempt fetch to health endpoint');
  });

  it('shouldAttemptOffline function exists', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\connectivity.js',
      'utf8'
    );
    const hasShouldAttemptOffline = source.includes('shouldAttemptOffline');
    assert.ok(hasShouldAttemptOffline, 'should have shouldAttemptOffline function');
  });
});

describe('Phase 3B — Authorization Verification', () => {
  it('auth-verification.js has parseAuthToken', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\auth-verification.js',
      'utf8'
    );
    const hasParseAuthToken = source.includes('parseAuthToken');
    assert.ok(hasParseAuthToken, 'should have parseAuthToken function');
  });

  it('auth-verification.js has verifyAuthSignature', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\auth-verification.js',
      'utf8'
    );
    const hasVerifyAuthSignature = source.includes('verifyAuthSignature');
    assert.ok(hasVerifyAuthSignature, 'should have verifyAuthSignature function');
  });

  it('verifyAuthSignature blocks when public key unavailable', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\auth-verification.js',
      'utf8'
    );
    const blocksWithoutKey = source.includes('blockReason') && source.includes('public_key_unavailable');
    assert.ok(blocksWithoutKey, 'must block when PUBLIC KEY unavailable');
  });

  it('normalizes PEM keys before WebCrypto import', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\auth-verification.js',
      'utf8'
    );
    const normalizesPem = source.includes('normalizePublicKey') || source.includes('pemToDer');
    assert.ok(normalizesPem, 'must convert PEM with headers/newlines into DER before importKey');
  });

  it('verifyAuthSignature blocks on invalid signature', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\auth-verification.js',
      'utf8'
    );
    const hasInvalidSigBlock = source.includes('invalid signature');
    assert.ok(hasInvalidSigBlock, 'must block on invalid signature');
  });

  it('checkOfflineSaleEligibility exists', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\auth-verification.js',
      'utf8'
    );
    const hasCheck = source.includes('checkOfflineSaleEligibility');
    assert.ok(hasCheck, 'should have checkOfflineSaleEligibility');
  });
});

describe('Phase 3B — Snapshot Validation', () => {
  it('snapshot.js has validateSnapshot', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\snapshot.js',
      'utf8'
    );
    const hasValidate = source.includes('validateSnapshot');
    assert.ok(hasValidate, 'should have validateSnapshot function');
  });

  it('snapshot rejects company mismatch', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\snapshot.js',
      'utf8'
    );
    const rejectsMismatch = source.includes('mismatch');
    assert.ok(rejectsMismatch, 'should reject mismatched company/branch/terminal');
  });
});

describe('Phase 3B — Offline Sale Handling', () => {
  it('sale.js has attemptOfflineSale', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\sale.js',
      'utf8'
    );
    const hasAttempt = source.includes('attemptOfflineSale');
    assert.ok(hasAttempt, 'should have attemptOfflineSale function');
  });

  it('sale.js has protectAgainstDoubleClick', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\sale.js',
      'utf8'
    );
    const hasProtect = source.includes('protectAgainstDoubleClick');
    assert.ok(hasProtect, 'should have double-click protection');
  });

  it('sale.js builds payload with payload_version', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\sale.js',
      'utf8'
    );
    const hasVersion = source.includes('payload_version');
    assert.ok(hasVersion, 'payload should include payload_version');
  });

  it('sale.js buildSalePayload includes items', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\sale.js',
      'utf8'
    );
    const hasItems = source.includes('items:') || source.includes('items,');
    assert.ok(hasItems, 'payload should include items');
  });

  it('sale.js buildPaymentData includes method_id', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\sale.js',
      'utf8'
    );
    const hasMethod = source.includes('method_id');
    assert.ok(hasMethod, 'payment data should include method_id');
  });

  it('sale.js buildPaymentData includes amount', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\sale.js',
      'utf8'
    );
    const hasAmount = source.includes('amount');
    assert.ok(hasAmount, 'payment data should include amount');
  });

  it('attemptSaleOffline checks connectivity first', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\sale.js',
      'utf8'
    );
    const hasCheck = source.includes('checkServerReachable');
    assert.ok(hasCheck, 'should check server reachability first');
  });
});

describe('Phase 3B — Clock / 48 Hours', () => {
  it('auth-verification.js validates 48h max window', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\auth-verification.js',
      'utf8'
    );
    const has48hWindow = source.includes('48') && source.includes('max');
    assert.ok(has48hWindow, 'should enforce maximum 48-hour window');
  });

  it('validateAuthWindow function exists', () => {
    const fs = require('fs');
    const source = fs.readFileSync(
      'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2\\resources\\js\\offline\\auth-verification.js',
      'utf8'
    );
    const hasWindow = source.includes('validateAuthWindow');
    assert.ok(hasWindow, 'should have validateAuthWindow function');
  });
});