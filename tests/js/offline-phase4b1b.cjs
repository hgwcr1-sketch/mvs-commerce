const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');

const ROOT = 'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2';
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8');

describe('Phase 4B.1B - real checkout gate', () => {
  const pos = () => read('resources/views/pos/index.blade.php');
  const sale = () => read('resources/js/offline/sale.js');

  it('keeps the official checkout URL and uses an online-first response gate', () => {
    const source = pos();
    assert.match(source, /route\('pos\.checkout'/);
    assert.match(source, /let response = null/);
    assert.match(source, /if \(!response && window\.MvsOffline\?\.attemptOfflineSale\)/);
    assert.match(source, /body: JSON\.stringify\(checkoutPayload\)/);
  });

  it('does not route Laravel responses into Offline', () => {
    const source = pos();
    assert.match(source, /if \(!response && window\.MvsOffline/);
    assert.doesNotMatch(source, /response\.ok[\s\S]{0,200}attemptOfflineSale/);
  });

  it('preserves checkout payload fields for Offline', () => {
    const source = pos();
    for (const field of ['checkout_token', 'cash_session_id', 'suspended_sale_id', 'recovery_token', 'quote_id', 'customer_id', 'document_type', 'requested_points', 'payments', 'items']) {
      assert.match(source, new RegExp(`${field}:`), `missing ${field}`);
    }
  });

  it('requires health failure plus readiness before enqueueing', () => {
    const source = sale();
    assert.match(source, /checkServerReachable\(\)/);
    assert.match(source, /getLastHealthStatus\(\)/);
    assert.match(source, /checkOfflineReady\(terminalContext\)/);
    assert.match(source, /canMakeOfflineSale\(terminalContext/);
    assert.match(source, /enqueueOperation\(/);
  });

  it('uses the real authenticated user and stable checkout UUID', () => {
    const source = sale();
    assert.match(source, /user_id: terminalContext\.user_id/);
    assert.match(source, /operation_uuid: saleData\.checkout_token/);
  });

  it('does not print or create a receipt after local acceptance', () => {
    const source = pos();
    const offlineBlock = source.slice(source.indexOf('if (!response && window.MvsOffline'), source.indexOf('this.checkout.error = error.message'));
    assert.doesNotMatch(offlineBlock, /attemptAutoPrint|printCompletedSale|receipt/);
    assert.match(offlineBlock, /Venta guardada Offline/);
  });
});

describe('Phase 4B.1B - durable sync acknowledgement', () => {
  it('stores server sale identifiers when ACK is processed', () => {
    const source = read('resources/js/offline/sync-worker.js');
    assert.match(source, /server_sale_id/);
    assert.match(source, /server_sale_number/);
    assert.match(source, /markSynced\(op\.id/);
  });

  it('preserves FIFO, single concurrency, and retry classifications', () => {
    const source = read('resources/js/offline/sync-worker.js');
    assert.match(source, /const MAX_CONCURRENT = 1/);
    assert.match(source, /PAUSE_BETWEEN_OPERATIONS_MS = 300/);
    assert.match(source, /status === 401 \|\| status === 403/);
    assert.match(source, /status === 409/);
    assert.match(source, /status === 400 \|\| status === 422/);
  });
});
