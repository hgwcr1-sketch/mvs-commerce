const { it } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const read = name => fs.readFileSync(path.resolve(__dirname, '../../resources/js/offline', name), 'utf8');
const source = name => read(name).replace(/^import\s+[\s\S]*?from\s+['"][^'"]+['"];\r?\n/gm, '').replace(/^export /gm, '').replace(/^\{ DB_NAME, DB_VERSION, STORES \};/m, '');
const tick = () => new Promise(resolve => setImmediate(resolve));

function fixture() {
  const req = {};
  const committed = [];
  let staged;
  const tx = { objectStore: () => ({ put: value => { staged = value; return req; } }) };
  const ctx = vm.createContext({ console, crypto: require('node:crypto').webcrypto,
    checkServerReachable: async () => 'offline', getLastHealthStatus: () => null,
    checkOfflineReady: async () => ({ ready: true }),
    canMakeOfflineSale: async () => ({ allowed: true }),
    getAuthorization: async () => ({ token: 'isolated-test' }), getSnapshot: async () => ({}),
    getByIndex: async () => [],
  });
  vm.runInContext(source('db.js'), ctx);
  ctx.testDB = { readyState: 'open', transaction: () => tx };
  vm.runInContext('dbInstance = testDB', ctx);
  vm.runInContext(source('pending-operations.js'), ctx);
  // Override read I/O only; execute the actual enqueue and put implementations.
  vm.runInContext('getByIndex = async () => []', ctx);
  vm.runInContext(source('sale.js'), ctx);
  const run = () => vm.runInContext(`attemptOfflineSale({
    terminalContext: { company_id: 1, branch_id: 2, user_id: 3, terminal_uuid: 'test' },
    saleData: { checkout_token: 'isolated-uuid', items: [], payments: [] }
  })`, ctx);
  return { req, tx, committed, run, commit() { committed.push(staged); tx.oncomplete?.(); } };
}

it('4B.1B returns success only after transaction commit, not request success', async () => {
  const f = fixture();
  let outcome;
  const done = f.run().then(value => { outcome = value; });
  await tick();
  f.req.result = 'test-key';
  f.req.onsuccess?.();
  await tick();
  assert.equal(outcome, undefined, 'request success must not confirm a sale');
  assert.equal(f.committed.length, 0);
  f.commit();
  await done;
  assert.equal(outcome.success, true);
  assert.equal(f.committed.length, 1);
  assert.equal(f.committed[0].status, 'pending');
  assert.equal(outcome.operation.operation_uuid, 'isolated-uuid');
});

it('transaction abort after request success never returns sale success', async () => {
  const f = fixture();
  const done = f.run();
  await tick();
  f.req.onsuccess?.();
  f.tx.error = { message: 'isolated abort' };
  f.tx.onabort?.();
  const result = await done;
  assert.equal(result.success, false);
  assert.match(result.reason, /abort/);
  assert.equal(f.committed.length, 0);
});
