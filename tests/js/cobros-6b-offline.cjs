// Executes the actual worker with a failed network; not a device/PWA installation test.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const listeners = {};
let networkCalls = 0;
const self = {addEventListener: (name, callback) => {listeners[name] = callback;}};
vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../public/sw-cobros.js'), 'utf8'), {
  self, URL, Response, console,
  caches: {match: async () => undefined},
  fetch: async () => {networkCalls++; throw new TypeError('Simulated connection lost');},
});
(async () => {
  for (const pathname of ['/cobros/payments', '/cobros/receipts/RCB-00000001']) {
    let response;
    self.onfetch({request: {method:'GET', url:'https://test.invalid'+pathname, mode:'navigate', headers:new Headers()}, respondWith: value => {response = value;}});
    const result = await response;
    assert.equal(result.status,503);
    assert.equal(result.ok,false);
    assert.ok((await result.text()).includes('Sin conexión'));
  }
  for (const request of [{method:'POST',url:'https://test.invalid/cobros/payments'}, {method:'GET',url:'https://test.invalid/pos'}]) {
    self.onfetch({request, respondWith: () => {throw new Error('Worker intercepted payment or Commerce');}});
  }
  assert.equal(networkCalls,2);
  let notified = 0;
  self.clients = {matchAll: async () => [{postMessage: data => {assert.equal(data.type,'cobros:sync-ready');notified++;}}]};
  let sync;
  listeners.sync({tag:'cobros-sync',waitUntil: value => {sync=value;}});
  await sync;
  assert.equal(notified,1);
  assert.equal(networkCalls,2,'Sync must never send a payment');
  console.log('PASS: offline GET 503; POST untouched; Commerce untouched; sync only notifies, no payment or false success (simulated network).');
})().catch(error => {console.error(error);process.exitCode=1;});
