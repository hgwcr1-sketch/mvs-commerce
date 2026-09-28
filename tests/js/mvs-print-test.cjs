const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function setup(options = {}) {
    const calls = { prints: [], connects: 0, browser: 0, urls: [] }, factories = {};
    const qz = {
        websocket: { isActive: () => options.active !== false, connect: async () => { calls.connects++; if(options.offline) throw Error('offline'); } },
        configs: { create: printer => ({ printer }) },
        security: { setCertificatePromise() {}, setSignatureAlgorithm() {}, setSignaturePromise() {} },
        print: async (config, data) => { calls.prints.push({config, data}); if(options.reject) throw Error('denied'); },
    };
    const context = {
        window: { qz: options.unavailable ? undefined : qz, print: () => calls.browser++, open: () => calls.browser++ },
        document: { addEventListener: (_, cb) => cb() }, Alpine: { data: (name, fn) => factories[name] = fn },
        localStorage: { getItem: () => 'terminal-uuid' }, URLSearchParams, TextEncoder, AbortController, setTimeout, clearTimeout,
        btoa: value => Buffer.from(value, 'binary').toString('base64'),
        fetch: async url => { calls.urls.push(url); return { ok: true, json: async () => ({success: true, qz: {signed_mode:true,certificate_url:'/cert',signature_url:'/sign'}, printer: options.noPrinter ? null : 'POS-58-Series', payload: {
            lines: [{type:'text',value:'Existing sale'}], paper_width:'58', auto_cut:true, open_drawer:true, drawer_command:[27,112,0,25,255],
        }}) }; },
    };
    vm.runInNewContext(fs.readFileSync('resources/js/mvs-print/qz.js','utf8'), context);
    context.window.MvsPrint._qzSigned = options.active !== false;
    context.window.MvsPrint._qzSignedUrls = '/cert|/sign';
    return {calls, qz, api:context.window.MvsPrint, ui:factories.mvsReprint('/mvs/print/ticket/7',7)};
}
test('reprint uses saved printer, RAW, cut, no drawer and no browser', async () => {
    const {ui,calls}=setup(); await ui.reprint();
    assert.equal(calls.connects,0); assert.equal(calls.prints.length,1);
    assert.equal(calls.prints[0].config.printer,'POS-58-Series');
    const data=calls.prints[0].data[0]; assert.equal(data.type,'raw'); assert.equal(data.flavor,'base64');
    const bytes=Buffer.from(data.data,'base64');
    assert.ok(bytes.includes(Buffer.from([29,86,66,0]))); assert.ok(!bytes.includes(Buffer.from([27,112,0,25,255])));
    assert.match(calls.urls[0],/reprint=1/); assert.match(calls.urls[0],/terminal_uuid=terminal-uuid/);
    assert.equal(ui.message,'Factura enviada a POS-58-Series'); assert.equal(ui.failed,false); assert.equal(calls.browser,0);
});
for(const options of [{unavailable:true},{active:false,offline:true},{reject:true},{noPrinter:true}]) {
    test('failure only offers manual fallback '+JSON.stringify(options), async () => {
        const {ui,calls}=setup(options); await ui.reprint();
        assert.equal(ui.failed,true); assert.equal(ui.busy,false); assert.equal(calls.browser,0);
        assert.ok(ui.message.length > 0);
    });
}
test('new sale connects successfully with undefined result and preserves drawer', async () => {
    const {api,calls}=setup({active:false}); const result=await api.printSale('/ticket/{sale}',7);
    assert.equal(result.success,true); assert.equal(calls.connects,1); assert.ok(!calls.urls[0].includes('reprint='));
    assert.ok(Buffer.from(calls.prints[0].data[0].data,'base64').includes(Buffer.from([27,112,0,25,255])));
    assert.equal(calls.browser,0);
});
test('QZ pending authorization keeps busy and prevents double click', async () => {
    const {ui,qz,calls}=setup(); let complete;
    qz.print=()=>new Promise(resolve=>complete=resolve);
    const pending=ui.reprint(); await new Promise(setImmediate); await ui.reprint();
    assert.equal(ui.busy,true); assert.equal(calls.urls.length,1); complete(); await pending; assert.equal(ui.busy,false);
});
test('cut false suppresses cut and manual fallback is gated in shared Blade', () => {
    const {api}=setup(); const bytes=Buffer.from(api.buildEscPosFromPayload({lines:[],auto_cut:false,open_drawer:false}));
    assert.ok(!bytes.includes(Buffer.from([29,86,66,0])));
    const blade=fs.readFileSync('resources/views/components/mvs-print/reprint.blade.php','utf8');
    assert.match(blade,/x-show="failed"/); assert.match(blade,/Usar impresión del navegador/);
    for(const file of ['index','show']) assert.match(fs.readFileSync(`resources/views/ventas/${file}.blade.php`,'utf8'),/x-mvs-print.reprint/);
});
function posState(api, { enabled = true, terminal = true, duplicate = false } = {}) {
    const source = fs.readFileSync('resources/views/pos/index.blade.php', 'utf8');
    const methods = source.slice(source.indexOf('        async confirmCheckout()'), source.indexOf('        newSale()'))
        .replace(/\{\{[^\n]*\}\}/g, "'/mvs/print/ticket/__SALE_ID__'");
    api.fetchConfig = async () => ({ auto_print: enabled, terminal: terminal ? { printer_name: 'POS-58-Series' } : null });
    const context = {
        window: { MvsPrint: api }, fetch: async () => ({}),
        document: { querySelector: () => ({ content: 'test' }) },
    };
    const state = vm.runInNewContext(`({${methods}})`, context);
    Object.assign(state, {
        checkoutCanConfirm: true, checkout: { payments: [], printStatus: null },
        suspended: {}, cart: [], documentType: 'electronic_ticket',
        clearSuspendedRecovery() {},
        readFetchResponse: async () => ({ success: true, duplicate, sale_id: 7, receipt_url: '/receipt/7' }),
    });
    return state;
}
test('actual checkout success trigger reaches QZ, auto_print true, without browser', async () => {
    const { api, calls } = setup({active:false});
    const state = posState(api);
    await state.confirmCheckout(); await new Promise(setImmediate);
    assert.equal(state.checkout.error, '');
    assert.equal(calls.prints.length, 1);
    assert.equal(state.checkout.printStatus, 'success');
    assert.equal(state.checkout.printMessage, 'Factura enviada a POS-58-Series');
    assert.equal(calls.browser, 0);
    assert.ok(!calls.urls[0].includes('reprint='));
});
test('actual checkout auto_print false and duplicate response do not print', async () => {
    for (const options of [{enabled:false}, {duplicate:true}]) {
        const { api, calls } = setup(); const state = posState(api, options);
        await state.confirmCheckout(); await new Promise(setImmediate);
        assert.equal(calls.prints.length, 0); assert.equal(calls.browser, 0);
    }
});
test('Demo without terminal displays unresolved company/branch message and manual fallback', async () => {
    const { api, calls } = setup(); const state = posState(api, {terminal:false});
    await state.confirmCheckout(); await new Promise(setImmediate);
    assert.equal(calls.prints.length, 0); assert.equal(calls.browser, 0);
    assert.equal(state.checkout.printStatus, 'failed');
    assert.match(state.checkout.printMessage, /esta empresa y sucursal/);
});
test('real post-sale button expression calls shared direct printing, suppresses drawer', async () => {
    const { api, calls } = setup(); const state = posState(api, {enabled:false});
    await state.confirmCheckout(); await new Promise(setImmediate);
    const source = fs.readFileSync('resources/views/pos/index.blade.php','utf8');
    const expression = source.match(/@click="(printCompletedSale\(checkout.result.sale_id\))"/)[1];
    await vm.runInNewContext(`state.${expression.replace('checkout.', 'state.checkout.')}`, {state});
    assert.equal(calls.prints.length, 1);
    assert.match(calls.urls[0], /reprint=1/);
    assert.ok(!Buffer.from(calls.prints[0].data[0].data,'base64').includes(Buffer.from([27,112,0,25,255])));
    assert.equal(calls.browser, 0);
});
test('automatic and manual post-sale failure never open browser', async () => {
    for (const options of [{unavailable:true}, {reject:true}, {active:false,offline:true}]) {
        const { api, calls } = setup(options); const state = posState(api);
        await state.confirmCheckout(); await new Promise(setImmediate);
        assert.equal(state.checkout.printStatus, 'failed');
        await state.printCompletedSale(7);
        assert.equal(state.checkout.printStatus, 'failed'); assert.equal(calls.browser, 0);
    }
    const source=fs.readFileSync('resources/views/pos/index.blade.php','utf8');
    assert.match(source, /<a x-show="checkout.printStatus === 'failed'" x-cloak :href="checkout.result.receipt_url"/);
    assert.match(source, /No fue posible imprimir directamente\./);
});
test('pending automatic config blocks manual click to avoid duplicate printing', async () => {
    const {api,calls}=setup(); const state=posState(api); let resolve;
    api.fetchConfig=()=>new Promise(done=>resolve=done);
    const pending=state.attemptAutoPrint(7);
    await state.printCompletedSale(7); assert.equal(calls.prints.length,0);
    resolve({auto_print:true,terminal:{printer_name:'POS-58-Series'}}); await pending;
    assert.equal(calls.prints.length,1);
});
