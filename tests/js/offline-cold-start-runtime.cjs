const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const { generateKeyPairSync, sign, webcrypto } = require('node:crypto');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const moduleUrl = (file) => pathToFileURL(path.join(root, file)).href;
const keys = generateKeyPairSync('rsa', { modulusLength: 2048 });
const publicKey = keys.publicKey.export({ type: 'spki', format: 'pem' });
global.window = { crypto: webcrypto };
const modules = Promise.all([
  import(moduleUrl('resources/js/offline/auth-verification.js')),
  import(moduleUrl('resources/js/offline/snapshot.js')),
  import(moduleUrl('resources/js/offline/pos-search.js')),
]);

function signed(payload) {
  const header = Buffer.from(JSON.stringify({ alg: 'RS256', typ: 'MVS-Offline-Auth', version: 1 })).toString('base64url');
  const data = `${header}.${Buffer.from(JSON.stringify(payload)).toString('base64url')}`;
  return `${data}.${sign('RSA-SHA256', Buffer.from(data), keys.privateKey).toString('base64url')}`;
}

function fixture() {
  const now = Date.now();
  const payload = {
    authorization_id: 'test-auth', company_id: 1, branch_id: 2, user_id: 3,
    terminal_uuid: 'terminal-test', issued_at: new Date(now - 60000).toISOString(),
    valid_until: new Date(now + 3600000).toISOString(), server_time: new Date(now - 60000).toISOString(),
    license_status: 'active',
  };
  const metadata = { ...payload };
  const snapshot = {
    schema_version: 1, generated_at: new Date(now).toISOString(),
    company: { id: 1, trade_name: 'Empresa' }, branch: { id: 2, name: 'Sucursal' },
    terminal: { terminal_uuid: 'terminal-test' }, user: { id: 3, name: 'Usuario' },
    products: [], customers: [],
  };
  return { payload, metadata, snapshot, publicKey, provisioned: true };
}

async function recover(f) {
  const [auth, snapshots, search] = await modules;
  const record = { ...f.metadata, token: f.token || signed(f.payload) };
  let reads = 0;
  const context = vm.createContext({
    console, Date, Number,
    localStorage: { getItem: () => f.publicKey === null ? null : JSON.stringify({ key: f.publicKey }) },
    STORES: { metadata: 'metadata' },
    getAll: async () => f.provisioned ? [record] : [],
    getSnapshot: async (ctx) => {
      reads++;
      assert.equal(ctx.user_id, f.metadata.user_id);
      return f.snapshot;
    },
    validateSnapshot: snapshots.validateSnapshot,
    verifyAuthSignature: auth.verifyAuthSignature,
    searchSnapshotProducts: search.searchSnapshotProducts,
    searchSnapshotCustomers: search.searchSnapshotCustomers,
  });
  // Execute the actual recovery code. Only storage I/O is replaced with
  // isolated fixtures; RSA/WebCrypto, token parsing and snapshot checks are real.
  const source = read('resources/js/offline/cold-start.js')
    .replace(/^import .*;\r?\n/gm, '').replace(/^export /gm, '');
  vm.runInContext(source, context);
  const result = await vm.runInContext('recoverContext()', context);
  assert.equal(reads, 1);
  return result;
}

describe('Cold Start runtime with real RSA-2048/SHA-256', () => {
  it('accepts the complete signed context without server calls', async () => {
    const result = await recover(fixture());
    assert.equal(result.auth.user_id, 3);
    assert.equal(result.context.user_id, result.snapshot.user.id);
  });

  const rejected = {
    'signed user differs from metadata': f => { f.payload.user_id = 4; },
    'signed user differs from snapshot': f => { f.snapshot.user.id = 4; },
    'legacy token has no signed user': f => { delete f.payload.user_id; },
    'metadata user is manipulated': f => { f.metadata.user_id = 4; },
    'metadata and snapshot user manipulated together': f => { f.metadata.user_id = 4; f.snapshot.user.id = 4; },
    'snapshot user is absent': f => { delete f.snapshot.user; },
    'company mismatch': f => { f.metadata.company_id = 9; },
    'branch mismatch': f => { f.metadata.branch_id = 9; },
    'terminal mismatch': f => { f.metadata.terminal_uuid = 'other'; },
    'snapshot company mismatch': f => { f.snapshot.company.id = 9; },
    'snapshot branch mismatch': f => { f.snapshot.branch.id = 9; },
    'snapshot terminal mismatch': f => { f.snapshot.terminal.terminal_uuid = 'other'; },
    'signed authorization expired despite renewed metadata': f => { f.payload.valid_until = new Date(Date.now() - 1000).toISOString(); },
    'signed authorization older than 48 hours': f => {
      f.payload.issued_at = new Date(Date.now() - 49 * 3600000).toISOString();
      f.payload.server_time = f.payload.issued_at;
    },
    'signed authorization window exceeds 48 hours': f => { f.payload.valid_until = new Date(Date.now() + 49 * 3600000).toISOString(); },
    'missing public key': f => { f.publicKey = null; },
    'invalid public key': f => { f.publicKey = '-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----'; },
    'invalid signature after user claim tampering': f => {
      const parts = signed(f.payload).split('.');
      parts[1] = Buffer.from(JSON.stringify({ ...f.payload, user_id: 4 })).toString('base64url');
      f.metadata.user_id = 4;
      f.snapshot.user.id = 4;
      f.token = parts.join('.');
    },
    'never provisioned': f => { f.provisioned = false; },
    'snapshot missing': f => { f.snapshot = null; },
  };
  for (const [name, mutate] of Object.entries(rejected)) {
    it(`fails closed: ${name}`, async () => {
      const f = fixture();
      mutate(f);
      await assert.rejects(() => recover(f));
    });
  }

  it('retains legacy signature verification for 4B.1B', async () => {
    const [auth] = await modules;
    const f = fixture();
    delete f.payload.user_id;
    const result = await auth.verifyAuthSignature(signed(f.payload), publicKey, f.metadata);
    assert.equal(result.valid, true);
    assert.equal(result.auth.user_id, undefined);
  });

  it('displays signed validity rather than locally extended metadata', async () => {
    const f = fixture();
    f.metadata.valid_until = new Date(Date.now() + 24 * 3600000).toISOString();
    const result = await recover(f);
    assert.equal(result.authorization.valid_until, f.payload.valid_until);
  });
});

describe('Online upgrade of legacy authorization', () => {
  async function provisionFixture(online, legacy) {
    const [auth] = await modules;
    const f = fixture();
    if (legacy) delete f.payload.user_id;
    let current = { ...f.metadata, token: signed(f.payload) };
    const requests = [];
    let saves = 0;
    let snapshotToken;
    const ctx = vm.createContext({
      console: { info() {}, warn() {} }, globalThis: {}, navigator: {},
      localStorage: { getItem: () => f.metadata.terminal_uuid },
      openDB: async () => {}, checkPublicKeyReady: async () => ({ ready: true }),
      getAuthorization: async () => current, isAuthorizationValid: async () => true,
      checkServerReachable: async () => online ? 'online' : 'offline', getLastHealthStatus: () => null,
      getSnapshotMetadata: async () => ({}), parseAuthToken: auth.parseAuthToken,
      saveAuthorization: async (value) => { current = value; saves++; },
      fetchAndStoreSnapshot: async ({ authorizationToken }) => { snapshotToken = authorizationToken; return { success: true }; },
      document: { querySelector: () => null },
      fetch: async (url) => {
        requests.push(url);
        return { ok: true, json: async () => ({ token: signed({ ...f.payload, user_id: 3 }) }) };
      },
    });
    vm.runInContext(read('resources/js/offline/bootstrap.js').replace(/^import .*;\r?\n/gm, '').replace(/^export /gm, ''), ctx);
    ctx.testContext = { company_id: 1, branch_id: 2, user_id: 3 };
    await vm.runInContext('provision(testContext)', ctx);
    return { requests, saves, snapshotToken, auth };
  }

  it('requests a server-signed user and refreshes snapshot when online', async () => {
    const result = await provisionFixture(true, true);
    assert.deepEqual(result.requests, ['/mvs/offline/provision', '/mvs/offline/authorize']);
    assert.equal(result.saves, 1);
    assert.equal(result.auth.parseAuthToken(result.snapshotToken).payload.user_id, 3);
  });

  it('preserves legacy Offline authorization without renewal or writes', async () => {
    const result = await provisionFixture(false, true);
    assert.deepEqual(result.requests, []);
    assert.equal(result.saves, 0);
  });

  it('does not renew a current signed-user authorization unnecessarily', async () => {
    const result = await provisionFixture(true, false);
    assert.deepEqual(result.requests, []);
    assert.equal(result.saves, 0);
  });
});
