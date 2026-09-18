const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');

const ROOT = 'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2';
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8');

const coldStart = () => read('resources/js/offline/cold-start.js');
const shell = () => read('public/offline-shell.html');
const worker = () => read('public/sw.js');

 describe('Phase 4B.1C - secure cold start', () => {
  it('loads a generic shell without Laravel HTML or private context', () => {
    const source = shell();
    assert.match(source, /offline-shell\.js/);
    assert.doesNotMatch(source, /csrf-token|company_id|user_id|password|session/);
  });

  it('requires a previously stored public key and IndexedDB authorization records', () => {
    const source = coldStart();
    assert.match(source, /mvs_offline_public_key/);
    assert.match(source, /getAll\(STORES\.metadata\)/);
    assert.match(source, /record\?\.token/);
  });

  it('recovers company, branch, user and terminal from one exact local context', () => {
    const source = coldStart();
    for (const field of ['company_id', 'branch_id', 'user_id', 'terminal_uuid']) assert.match(source, new RegExp(field));
    assert.match(source, /candidates\.length > 1/);
    assert.match(source, /more than one|más de una identidad/i);
  });

  it('fails closed for missing, expired, over-48-hour, corrupt or invalid authorization', () => {
    const source = coldStart();
    assert.match(source, /valid_until/);
    assert.match(source, /MAX_OFFLINE_WINDOW_MS = 48 \* 60 \* 60 \* 1000/);
    assert.match(source, /verifyAuthSignature/);
    assert.match(source, /No existe una identidad Offline autorizada/);
    assert.match(source, /Clave pública Offline ausente o inválida/);
  });

  it('rejects snapshot absence, corruption and context/user mismatch', () => {
    const source = coldStart();
    assert.match(source, /getSnapshot\(candidate\.context\)/);
    assert.match(source, /validateSnapshot\(snapshot, candidate\.context\)/);
    assert.match(source, /snapshot\.user\?\.id/);
    assert.match(source, /Snapshot Offline ausente/);
    assert.match(source, /no coincide con la autorización/);
  });

  it('exposes products, existing customers and cart construction after validation', () => {
    const source = coldStart();
    assert.match(source, /searchSnapshotProducts/);
    assert.match(source, /searchSnapshotCustomers/);
    assert.match(source, /cart\.push/);
    assert.match(source, /POS Offline disponible/);
    assert.match(shell(), /Productos|Clientes|Carrito/);
  });

  it('keeps cash closed when no recoverable cash session exists', () => {
    const source = coldStart();
    assert.match(source, /cobro.*bloqueado|Cobro bloqueado/i);
    assert.match(shell(), /4B\.1D/);
    assert.match(shell(), /disabled/);
    assert.doesNotMatch(source, /cash_session_id\s*[:=]\s*\d/);
  });

  it('does not renew authorization, store passwords or private keys', () => {
    const source = coldStart();
    assert.doesNotMatch(source, /saveAuthorization|valid_until\s*=|password|private.?key/i);
    assert.doesNotMatch(source, /fetch\(/);
  });
});

describe('Phase 4B.1C - service worker shell safety', () => {
  it('caches versioned generic shell assets and Vite manifest', () => {
    const source = worker();
    assert.match(source, /mvs-pos-shell-v5/);
    assert.match(source, /offline-shell\.html/);
    assert.match(source, /offline-shell\.js/);
    assert.match(source, /build\/manifest\.json/);
  });

  it('keeps POST, API, offline and sync requests network-only', () => {
    const source = worker();
    assert.match(source, /request\.method !== 'GET'/);
    assert.match(source, /'\/api\/'/);
    assert.match(source, /'\/mvs\/offline\/'/);
    assert.match(source, /network-only/i);
  });

  it('never stores authenticated POS HTML or dynamic enterprise data', () => {
    const source = worker();
    assert.match(source, /Authenticated POS HTML is never written/);
    assert.match(source, /Does NOT cache dynamic enterprise data/);
    assert.doesNotMatch(source, /cache\.put\(request, response\.clone\(\)\)[\s\S]{0,80}isAppNavigation/);
  });

  it('preserves IndexedDB and pending operations by changing only cache version', () => {
    const source = coldStart();
    const database = read('resources/js/offline/db.js');
    assert.match(database, /mvs-commerce-offline/);
    assert.match(database, /pending_operations/);
    assert.doesNotMatch(source, /deleteDB\(|clearStore\(/);
  });

  it('loads the cold-start module through the real Vite entrypoint', () => {
    assert.match(read('resources/js/app.js'), /offline\/cold-start\.js/);
    assert.match(read('public/offline-shell.js'), /resources\/js\/app\.js/);
  });
});
