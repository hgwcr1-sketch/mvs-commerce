const assert = require('node:assert/strict');
const vm = require('node:vm');
const html = require('node:fs').readFileSync(0, 'utf8');
const script = [...html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].map(m => m[1]).find(s => s.includes("Alpine.data('posTerminal'"));
assert.ok(script);
let factory;
const calls = [];
const bearerPayload = {
    credit_note_id: 7,
    credit_note_number: 'NC-00000001',
    balance: '10000.0000',
    issued_at: '2026-01-01T10:00:00-06:00',
    expires_at: null,
};
const fetch = (url, options) => {
    calls.push({ url: String(url), body: options && options.body ? JSON.parse(options.body) : null });
    const path = String(url);
    if (path.includes('validar-portador')) {
        const body = options ? JSON.parse(options.body) : {};
        if (body.application_code === 'WRONG-0000-0000') {
            return Promise.resolve({ ok: false, redirected: false, headers: { get: () => 'application/json' }, json: () => Promise.resolve({ message: 'No se pudo validar la nota de crédito.' }) });
        }
        return Promise.resolve({ ok: true, redirected: false, headers: { get: () => 'application/json' }, json: () => Promise.resolve(bearerPayload) });
    }
    if (path.includes('cobrar')) {
        return Promise.resolve({ ok: true, redirected: false, headers: { get: () => 'application/json' }, json: () => Promise.resolve({ success: true, duplicate: false, message: 'Venta completada', sale_id: 1, total: 0, payments: [] }) });
    }
    return Promise.resolve({ ok: false, redirected: false, headers: { get: () => 'application/json' }, json: () => Promise.resolve({ message: 'error' }) });
};
vm.runInNewContext(script, {
    document: { addEventListener: (_, fn) => fn(), querySelector: () => ({ content: 'csrf-token' }) },
    Alpine: { data: (_, fn) => { factory = fn; } },
    console,
    window: { location: { origin: 'http://localhost' } },
    fetch,
});
const pos = factory();
pos.$nextTick = (fn) => fn();
pos.cart = [{ id: 1, quantity: 1, sale_price: 10000, tax_rate: 0, _discount: 0, _discountType: 'fixed', _unitPrice: '' }];

(async () => {
    // 1) Gating del botón "Aplicar" (bearerCanValidate).
    pos.bearerNC.number = 'NC-00000001';
    pos.bearerNC.code = 'ABCD-EFGH-IJKL';
    pos.bearerNC.amount = '10000';
    assert.equal(pos.bearerCanValidate, true);
    for (const [n, c, a] of [['', 'ABCD-EFGH-IJKL', '10000'], ['NC-00000001', '', '10000'], ['NC-00000001', 'ABCD-EFGH-IJKL', ''], ['XYZ-00000001', 'ABCD-EFGH-IJKL', '10000'], ['NC-00000001', 'ABCD-EFGH-IJKL', '0'], ['NC-00000001', 'ABCD-EFGH-IJKL', '10001'], ['NC-00000001', 'ABCD-EFGH-IJKL', '10000.00001'], ['NC-00000001', 'INVALID_CODE_WITH_MANY_REPEATED_CHARACTERS_123456789', '10000']]) {
        pos.bearerNC.number = n; pos.bearerNC.code = c; pos.bearerNC.amount = a;
        assert.equal(pos.bearerCanValidate, false, JSON.stringify([n, c, a]));
    }
    pos.bearerNC.number = 'NC-00000001'; pos.bearerNC.code = 'ABCD-EFGH-IJKL'; pos.bearerNC.amount = '10000';
    pos.checkout.processing = true;
    assert.equal(pos.bearerCanValidate, false);
    pos.checkout.processing = false;

    // 2) El panel abre con el saldo pendiente y cierra limpiando el código.
    pos.openBearerPanel();
    assert.equal(pos.bearerNC.open, true);
    assert.equal(pos.bearerNC.amount, '10000');
    pos.bearerNC.code = 'ABCD-EFGH-IJKL';
    pos.closeBearerPanel();
    assert.equal(pos.bearerNC.open, false);
    assert.equal(pos.bearerNC.code, '');

    // 3) Aplicar NC valida, agrega la línea portador y limpia el formulario,
    //    dejando el código únicamente en memoria.
    pos.bearerNC.number = 'NC-00000001';
    pos.bearerNC.code = 'ABCD-EFGH-IJKL';
    pos.bearerNC.amount = '10000';
    await pos.applyBearerNote();
    assert.equal(pos.creditNotes.selected.length, 1);
    assert.equal(pos.creditNotes.selected[0].bearer, true);
    assert.equal(pos.creditNotes.selected[0].credit_note_number, 'NC-00000001');
    assert.equal(pos.creditNotes.selected[0].code, 'ABCD-EFGH-IJKL');
    assert.equal(pos.bearerNC.open, false);
    assert.equal(pos.bearerNC.number, '');
    assert.equal(pos.bearerNC.code, '');
    assert.equal(pos.totalCreditNotesApplied, 10000);
    assert.equal(pos.pendingBalance, 0);

    // 4) Error genérico mostrado cuando la prevalidación falla (saldo liberado).
    assert.equal(pos.creditNotes.selected.filter(a => a.bearer).length, 1);
    pos.creditNotes.selected = [];
    assert.equal(pos.pendingBalance, 10000);
    pos.openBearerPanel();
    assert.equal(pos.bearerNC.open, true);
    pos.bearerNC.number = 'NC-00000002'; pos.bearerNC.code = 'WRONG-0000-0000'; pos.bearerNC.amount = '5000';
    assert.equal(pos.bearerCanValidate, true);
    await pos.applyBearerNote();
    assert.equal(pos.bearerNC.error, 'No se pudo validar la nota de crédito.');
    assert.equal(pos.creditNotes.selected.filter(a => a.bearer).length, 0);
    assert.equal(pos.bearerNC.open, true);
    pos.closeBearerPanel();

    // 4b) Re-aplicación de la nota válida para dejarla preparada en memoria.
    pos.openBearerPanel();
    pos.bearerNC.number = 'NC-00000001'; pos.bearerNC.code = 'ABCD-EFGH-IJKL'; pos.bearerNC.amount = '10000';
    await pos.applyBearerNote();
    assert.equal(pos.creditNotes.selected.length, 1);
    assert.equal(pos.creditNotes.selected[0].bearer, true);
    assert.equal(pos.totalCreditNotesApplied, 10000);

    // 5) La línea preparada nunca expone el código y el payload checkout usa
    //    los campos secretos únicamente en el cuerpo de la petición.
    pos.fetch = fetch;
    pos.creditNotes.selected.push({ credit_note_id: 9, credit_note_number: 'NC-00000009', amount: '10000', balance: '10000.0000', issued_at: '2026-01-01T10:00:00-06:00', expires_at: null, bearer: false });
    pos.cart = [{ id: 1, quantity: 2, sale_price: 10000, tax_rate: 0, _discount: 0, _discountType: 'fixed', _unitPrice: '' }];
    assert.equal(pos.grandTotal, 20000);
    assert.equal(pos.totalCreditNotesApplied, 20000);
    assert.equal(pos.pendingBalance, 0);
    assert.equal(pos.checkoutCanConfirm, true);
    await pos.confirmCheckout();
    const checkoutCall = calls.find(c => c.url.includes('cobrar'));
    assert.ok(checkoutCall);
    assert.deepEqual(checkoutCall.body.credit_note_bearer_applications, [{ credit_note_number: 'NC-00000001', application_code: 'ABCD-EFGH-IJKL', amount: '10000' }]);
    assert.deepEqual(checkoutCall.body.credit_note_applications, [{ credit_note_id: 9, amount: '10000' }]);
    // Tras confirmar, el código desaparece de toda la memoria de la terminal.
    assert.ok(!pos.creditNotes.selected.some(a => a.bearer));
    assert.ok(!JSON.stringify(pos).includes('ABCD-EFGH-IJKL'), 'el código no debe permanecer en el estado tras confirmar');

    // 6) El secreto nunca se persiste ni se vincula como salida: solo viaja en el
    //    cuerpo de la petición y se captura en el campo enmascarado.
    assert.ok(html.includes('x-model="bearerNC.code"'));
    assert.ok(!html.includes('x-text="bearerNC.code"'));
    assert.ok(!html.includes('localStorage'));
    assert.ok(!html.includes('sessionStorage'));

    console.log('CreditNote bearer UI OK');
})().catch((error) => { console.error(error); process.exit(1); });