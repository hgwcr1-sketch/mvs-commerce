const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');

const ROOT = 'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2';
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8');

describe('Offline sync runtime wake-up', () => {
  const bootstrap = () => read('resources/js/offline/bootstrap.js');
  const worker = () => read('resources/js/offline/sync-worker.js');

  it('starts after DOMContentLoaded so POS context is available on reload', () => {
    const source = bootstrap();
    assert.match(source, /document\.readyState === 'loading'/);
    assert.match(source, /addEventListener\('DOMContentLoaded', start/);
    assert.match(source, /scheduleSync\(provisioned\)/);
  });

  it('keeps an initial drain when the backend is already available', () => {
    const source = bootstrap();
    assert.match(source, /const provisioned = await provision\(context\)/);
    assert.match(source, /scheduleSync\(provisioned\)/);
  });

  it('detects backend recovery independently from navigator online events', () => {
    const source = bootstrap();
    assert.match(source, /RECOVERY_CHECK_INTERVAL_MS = 30000/);
    assert.match(source, /checkServerReachable\(\)/);
    assert.match(source, /scheduleBackendRecovery\(context\)/);
    assert.match(source, /window\.addEventListener\('online'/);
  });

  it('does not let recovery polling cancel a pending backoff timer', () => {
    const source = bootstrap();
    assert.match(source, /if \(syncTimer\)/);
    assert.match(source, /if \(delay === 0\) return/);
    assert.match(source, /summary\.reason === 'transient'/);
  });

  it('filters pending operations by all active user context fields', () => {
    const source = worker();
    assert.match(source, /context\.company_id/);
    assert.match(source, /context\.branch_id/);
    assert.match(source, /context\.user_id/);
    assert.match(source, /context\.terminal_uuid/);
    assert.match(source, /Number\(op\.user_id\) === Number\(context\.user_id\)/);
  });

  it('preserves FIFO, one request at a time, and durable ACK fields', () => {
    const source = worker();
    assert.match(source, /const MAX_CONCURRENT = 1/);
    assert.match(source, /for \(const op of pending\)/);
    assert.match(source, /PAUSE_BETWEEN_OPERATIONS_MS/);
    assert.match(source, /server_sale_id/);
    assert.match(source, /server_sale_number/);
    assert.match(source, /acked_at/);
    assert.doesNotMatch(source, /deleteOperation\(op\.id\)/);
  });
});
