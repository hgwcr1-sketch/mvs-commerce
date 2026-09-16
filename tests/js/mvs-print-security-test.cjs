const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const crypto = require('node:crypto');

const key = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
const pem = '-----BEGIN CERTIFICATE-----\ntest-public-certificate\n-----END CERTIFICATE-----';
const signing = { signed_mode: true, certificate_url: '/certificate', signature_url: '/signature' };
const payload = { lines: [{ type: 'text', value: 'Venta prueba' }], paper_width: '58' };
const never = () => new Promise(() => {});

// The unmodified installed QZ 2.2.6 library runs all security and wire serialization.
// Only the local device transport and HTTP server are simulated.
function setup(fault = {}) {
    const factories = {}, wire = [], requests = [], order = [];
    let sockets = 0;
    class Socket {
        static OPEN = 1;
        static CLOSED = 3;
        constructor() {
            sockets++;
            this.readyState = 0;
            if (fault.connect === 'timeout') return;
            setImmediate(() => {
                if (fault.connect === 'error') return this.onerror(new Error('offline'));
                this.readyState = 1;
                this.onopen({});
            });
        }
        send(raw) {
            if (raw === 'ping') return;
            const message = JSON.parse(raw);
            wire.push(message);
            const operation = message.call === 'printers.find' ? 'discovery' : message.call === 'print' ? 'print' : null;
            if (fault[operation] === 'timeout') return;
            setImmediate(() => this.onmessage({ data: JSON.stringify({
                uid: message.uid,
                ...(fault[operation] === 'error' ? { error: 'device error' } : {
                    result: message.call === 'getVersion' ? '2.2.6' : message.call === 'printers.find' ? ['POS-58-Series'] : null,
                }),
            }) }));
        }
        close() {
            this.readyState = 3;
            this.onclose?.({});
        }
    }
    const context = {
        window: {}, console: { log() {}, info() {}, warn() {}, error() {} },
        document: { addEventListener: (_, cb) => cb(), querySelector: () => ({ content: 'csrf-test' }) },
        Alpine: { data: (name, fn) => factories[name] = fn },
        localStorage: { getItem: () => 'terminal' },
        URLSearchParams, TextEncoder, AbortController, setTimeout, clearTimeout, Promise,
        setInterval: () => 0, clearInterval() {},
        btoa: value => Buffer.from(value, 'binary').toString('base64'),
        fetch: async (url, options = {}) => {
            requests.push({ url, options });
            const kind = url === '/certificate' ? 'certificate' : url === '/signature' ? 'signature' : 'fetch';
            if (fault[kind] === 'timeout') return never();
            if (fault[kind] === 'error') return { ok: false, status: 403 };
            return {
                ok: true, status: 200,
                text: async () => {
                    if (kind === 'certificate') return pem;
                    const exact = JSON.parse(options.body).request;
                    return crypto.sign('sha512', Buffer.from(exact), key.privateKey).toString('base64');
                },
                json: async () => ({ success: true, printer: 'POS-58-Series', payload, qz: signing }),
            };
        },
    };
    vm.createContext(context);
    vm.runInContext(fs.readFileSync('node_modules/qz-tray/qz-tray.js', 'utf8'), context);
    const qz = context.window.qz;
    assert.equal(qz.version, '2.2.6');
    qz.api.setWebSocketType(Socket);
    qz.api.setSha256Type(value => crypto.createHash('sha256').update(value).digest('hex'));
    for (const [object, name] of [[qz.security,'setCertificatePromise'],[qz.security,'setSignatureAlgorithm'],[qz.security,'setSignaturePromise'],[qz.websocket,'connect'],[qz.websocket,'disconnect']]) {
        const original = object[name];
        object[name] = (...args) => { order.push(name); return original(...args); };
    }
    vm.runInContext(fs.readFileSync('resources/js/mvs-print/qz.js', 'utf8'), context);
    const api = context.window.MvsPrint;
    // Successful connections must tolerate compiler/CI load; intentional hangs stay short.
    api.timeoutMs = Object.values(fault).includes('timeout') ? 80 : 2000;
    api.printTimeoutMs = api.timeoutMs;
    const ui = factories.mvsPrintQz({ signedMode: true, certificateUrl: '/certificate', signatureUrl: '/signature', testUrl: '/test/__ID__', drawerUrl: '/drawer/__ID__' });
    return { api, ui, qz, wire, requests, order, fault, sockets: () => sockets, reprint: factories.mvsReprint('/ticket/7', 7) };
}

test('real QZ 2.2.6 sends certificate and SHA512 signature over exact requested hash, then reuses connection', async () => {
    const h = setup();
    assert.equal((await h.api.printSale('/ticket/7',7)).success, true);
    assert.deepEqual(h.order.slice(0,4), ['setCertificatePromise','setSignatureAlgorithm','setSignaturePromise','connect']);
    assert.equal(h.wire.find(m => 'certificate' in m).certificate, pem);
    const printed = h.wire.find(m => m.call === 'print');
    assert.equal(printed.signAlgorithm, 'SHA512');
    const exact = crypto.createHash('sha256').update(JSON.stringify({ call: printed.call, params: printed.params, timestamp: printed.timestamp })).digest('hex');
    assert.equal(JSON.parse(h.requests.find(r => r.url === '/signature').options.body).request, exact);
    assert.equal(crypto.verify('sha512', Buffer.from(exact), key.publicKey, Buffer.from(printed.signature, 'base64')), true);
    await h.api.printSale('/ticket/8',8);
    assert.equal(h.sockets(),1);
    await h.qz.websocket.disconnect();
});

test('existing anonymous connection is disconnected before security and reconnect', async () => {
    const h = setup();
    h.qz.security.setCertificatePromise(resolve => resolve(''));
    await h.qz.websocket.connect();
    h.order.length = 0;
    assert.equal((await h.api.printSale('/ticket/7',7)).success,true);
    assert.deepEqual(h.order.slice(0,5),['disconnect','setCertificatePromise','setSignatureAlgorithm','setSignaturePromise','connect']);
    await h.qz.websocket.disconnect();
});

for (const operation of ['connect','certificate','signature','print','fetch']) {
    for (const outcome of ['error','timeout']) {
        test(operation + ' ' + outcome + ' releases reprint UI and never resubmits', async () => {
            const h = setup({[operation]:outcome});
            await h.reprint.reprint();
            assert.equal(h.reprint.busy,false);
            assert.equal(h.reprint.failed,true);
            assert.equal(h.api._saleBusy,false);
            assert.equal(h.api._connecting,null);
            assert.ok(h.wire.filter(m => m.call === 'print').length <= 1);
            if (['certificate','signature'].includes(operation)) assert.equal(h.wire.filter(m => m.call === 'print').length,0);
            if (h.qz.websocket.isActive()) await h.qz.websocket.disconnect();
        });
    }
}

for (const outcome of ['success','error','timeout']) {
    test('check ' + outcome + ' always settles and shares concurrent connection', async () => {
        const h = setup(outcome === 'success' ? {} : {connect:outcome});
        await Promise.all([h.ui.check(), h.ui.check()]);
        assert.equal(h.ui.checking,false);
        assert.equal(h.ui.connected,outcome === 'success');
        assert.equal(h.order.filter(x => x === 'connect').length,1);
        if (h.qz.websocket.isActive()) await h.qz.websocket.disconnect();
    });
    for (const method of ['listPrinters','testPrint','openDrawer']) {
        test(method + ' ' + outcome + ' releases busy and prevents double click', async () => {
            const h = setup();
            await h.ui.check();
            if (outcome !== 'success') h.fault[method === 'listPrinters' ? 'discovery' : 'print'] = outcome;
            await Promise.all([h.ui[method](1),h.ui[method](1)]);
            assert.equal(h.ui.busy,false);
            assert.equal(h.ui.checking,false);
            const call = method === 'listPrinters' ? 'printers.find' : 'print';
            assert.equal(h.wire.filter(m => m.call === call).length,1);
            if (outcome === 'timeout') assert.match(h.ui.message,/espera|confirmar/);
            if (h.qz.websocket.isActive()) await h.qz.websocket.disconnect();
        });
    }
}

test('QR byte frame includes model 2 and UTF8 data length plus three control bytes', () => {
    const h = setup();
    const data = 'https://example.test/portal/empresa';
    const bytes = Buffer.from(h.api.buildEscPosFromPayload({ lines: [{type:'qr',value:data}] }));
    assert.ok(bytes.includes(Buffer.from([29,40,107,4,0,49,65,50,0])));
    assert.ok(bytes.includes(Buffer.from([29,40,107,Buffer.byteLength(data)+3,0,49,80,48,...Buffer.from(data)])));
});
