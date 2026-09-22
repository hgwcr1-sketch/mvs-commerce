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
        if (String(body.application_code || '').includes('WRONG')) {
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
    // 1) Prefijo NC- fijo: la UI muestra "NC-" estático y el input solo edita
    //    el sufijo numérico.
    assert.ok(html.includes('>NC-</span>'));
    pos.sanitizeBearerNumber('NC-00000004');
    assert.equal(pos.bearerNC.number, '00000004');
    assert.equal(pos.bearerNormalizedNumber, 'NC-00000004');
    pos.sanitizeBearerNumber('4');
    assert.equal(pos.bearerNormalizedNumber, 'NC-00000004');
    pos.sanitizeBearerNumber('00000004');
    assert.equal(pos.bearerNormalizedNumber, 'NC-00000004');
    pos.sanitizeBearerNumber('nc-00000004');
    assert.equal(pos.bearerNormalizedNumber, 'NC-00000004');

    // 2) Normalización estricta: texto arbitrario no se convierte en número.
    pos.sanitizeBearerNumber('ABC123');
    assert.equal(pos.bearerNormalizedNumber, null);
    pos.sanitizeBearerNumber('');
    assert.equal(pos.bearerNormalizedNumber, null);

    // 3) Código autoformateado en mayúsculas con guiones cada 4.
    pos.sanitizeBearerCode('6cnua5a3uj28');
    assert.equal(pos.bearerNC.code, '6CNU-A5A3-UJ28');
    assert.equal(pos.bearerRawCode, '6CNUA5A3UJ28');
    pos.sanitizeBearerCode('6CNU-A5A3-UJ28');
    assert.equal(pos.bearerRawCode, '6CNUA5A3UJ28');
    pos.sanitizeBearerCode('ABCDEFGHIJKLMNOP');
    assert.equal(pos.bearerRawCode, 'ABCDEFGHIJKL');
    assert.equal(pos.bearerCodeValid, true);

    // 4) Validar credenciales SIN monto previo.
    pos.openBearerPanel();
    assert.equal(pos.bearerNC.amount, '');
    pos.sanitizeBearerNumber('1');
    pos.sanitizeBearerCode('ABCDEFGHIJKL');
    assert.equal(pos.bearerCanValidate, true);
    assert.equal(pos.bearerCanApply, false);
    await pos.validateBearerNote();
    const validateCall = calls[calls.length - 1];
    assert.ok(validateCall.url.includes('validar-portador'));
    assert.ok(!('amount' in (validateCall.body || {})), 'la validación no debe enviar monto');
    assert.ok(pos.bearerNC.validated);
    assert.equal(pos.bearerNC.amount, '10000'); // MIN(saldo 10000, pendiente 10000)

    // 5) Monto automático editable hacia abajo y límites.
    pos.bearerNC.amount = '2000';
    assert.equal(pos.bearerCanApply, true);
    pos.bearerNC.amount = '10001';
    assert.equal(pos.bearerCanApply, false); // monto > saldo NC
    pos.bearerNC.amount = '0';
    assert.equal(pos.bearerCanApply, false); // monto <= 0
    pos.bearerNC.amount = '';
    assert.equal(pos.bearerCanApply, false);
    pos.bearerNC.amount = '10000';
    assert.equal(pos.bearerCanApply, true);

    // 6) Aplicación parcial conserva saldo y mismo código.
    await pos.applyBearerNote();
    assert.equal(pos.creditNotes.selected.length, 1);
    assert.equal(pos.creditNotes.selected[0].bearer, true);
    assert.equal(pos.creditNotes.selected[0].credit_note_number, 'NC-00000001');
    assert.equal(pos.creditNotes.selected[0].code, 'ABCDEFGHIJKL');
    assert.equal(pos.creditNotes.selected[0].amount, '10000');
    assert.equal(pos.totalCreditNotesApplied, 10000);
    assert.equal(pos.bearerNC.open, false);

    // 7) Siguiente uso recalcula MIN(saldo actual NC, pendiente venta).
    pos.creditNotes.selected = [];
    bearerPayload.balance = '56274.0000';
    pos.cart = [{ id: 1, quantity: 1, sale_price: 71981, tax_rate: 0, _discount: 0, _discountType: 'fixed', _unitPrice: '' }];
    assert.equal(pos.pendingBalance, 71981);
    pos.openBearerPanel();
    pos.sanitizeBearerNumber('NC-00000004');
    pos.sanitizeBearerCode('6CNUA5A3UJ28');
    await pos.validateBearerNote();
    assert.equal(pos.bearerNC.validated.balance, '56274.0000');
    assert.equal(pos.bearerNC.amount, '56274'); // MIN(56274, 71981)

    // 7b) El cajero reduce el monto: se aplica el menor y el resto queda en saldo.
    pos.bearerNC.amount = '20000';
    assert.equal(pos.bearerSaldoDespues, '36274');
    await pos.applyBearerNote();
    assert.equal(pos.creditNotes.selected[0].amount, '20000');
    assert.equal(pos.pendingBalance, 71981 - 20000);

    // 7c) Segunda venta con el saldo restante: recálculo automático.
    pos.creditNotes.selected = [];
    pos.cart = [{ id: 1, quantity: 1, sale_price: 15000, tax_rate: 0, _discount: 0, _discountType: 'fixed', _unitPrice: '' }];
    bearerPayload.balance = '36274.0000';
    pos.openBearerPanel();
    pos.sanitizeBearerNumber('NC-00000004');
    pos.sanitizeBearerCode('6CNUA5A3UJ28');
    await pos.validateBearerNote();
    assert.equal(pos.bearerNC.amount, '15000'); // MIN(36274, 15000)
    await pos.applyBearerNote();
    assert.equal(pos.creditNotes.selected[0].amount, '15000');

    // 8) Error genérico: código incorrecto (sin línea agregada).
    pos.creditNotes.selected = [];
    pos.cart = [{ id: 1, quantity: 1, sale_price: 10000, tax_rate: 0, _discount: 0, _discountType: 'fixed', _unitPrice: '' }];
    pos.openBearerPanel();
    pos.sanitizeBearerNumber('NC-00000002');
    pos.sanitizeBearerCode('WRONG00000000');
    await pos.validateBearerNote();
    assert.equal(pos.bearerNC.error, 'No se pudo validar la nota de crédito.');
    assert.equal(pos.bearerNC.validated, null);
    assert.equal(pos.creditNotes.selected.filter(a => a.bearer).length, 0);
    assert.equal(pos.bearerNC.open, true);
    pos.closeBearerPanel();

    // 9) Preparar línea completa para el checkout.
    pos.openBearerPanel();
    pos.sanitizeBearerNumber('NC-00000001');
    pos.sanitizeBearerCode('ABCDEFGHIJKL');
    bearerPayload.balance = '10000.0000';
    await pos.validateBearerNote();
    await pos.applyBearerNote();
    assert.equal(pos.creditNotes.selected.length, 1);
    assert.equal(pos.totalCreditNotesApplied, 10000);

    // 10) El payload checkout usa el código únicamente en el cuerpo.
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
    assert.deepEqual(checkoutCall.body.credit_note_bearer_applications, [{ credit_note_number: 'NC-00000001', application_code: 'ABCDEFGHIJKL', amount: '10000' }]);
    assert.deepEqual(checkoutCall.body.credit_note_applications, [{ credit_note_id: 9, amount: '10000' }]);
    assert.ok(!pos.creditNotes.selected.some(a => a.bearer));
    assert.ok(!JSON.stringify(pos).includes('ABCDEFGHIJKL'), 'el código no debe permanecer en el estado tras confirmar');

    // 11) El secreto nunca se persiste ni se vincula como salida: solo viaja en
    //     el cuerpo de la petición y se captura en el campo enmascarado.
    assert.ok(html.includes(':value="bearerNC.code"'));
    assert.ok(!html.includes('x-text="bearerNC.code"'));
    assert.ok(!html.includes('localStorage'));
    assert.ok(!html.includes('sessionStorage'));

    console.log('CreditNote bearer UI OK');
})().catch((error) => { console.error(error); process.exit(1); });