let checks = 0;
const assert = new Proxy(require('node:assert/strict'), {
    get: (target, key) => (...args) => { checks++; return target[key](...args); },
});
const vm = require('node:vm');
const html = require('node:fs').readFileSync(0, 'utf8');
const script = [...html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)]
    .map(match => match[1]).find(source => source.includes("Alpine.data('posTerminal'"));
assert.ok(script);
let factory;
let requests = [];
const reply = (payload, status = 200) => ({ ok: status < 400, status, headers: { get: () => 'application/json' }, json: async () => payload });
let handler = async () => reply([]);
const browserWindow = { location: { origin: 'http://localhost', search: '' }, confirm: () => true, addEventListener: () => {} };
vm.runInNewContext(script, {
    document: { addEventListener: (_, fn) => fn(), querySelector: () => ({ content: 'test-csrf' }) },
    Alpine: { data: (_, fn) => { factory = fn; } }, URL, URLSearchParams, console,
    window: browserWindow,
    fetch: async (url, options = {}) => { requests.push({ url: String(url), options }); return handler(url, options); },
});
const fresh = () => {
    const pos = factory();
    pos.$nextTick = () => {};
    pos.$refs = {};
    pos.$watch = () => {};
    return pos;
};
const product = { id: 1, name: 'Sin stock', sale_price: 1000, tax_rate: 0, controls_inventory: true, available_stock: 0, can_add_to_cart: false };
const stocked = { ...product, available_stock: 2, can_add_to_cart: true };
const enterButton = html.match(/<button[^>]*x-show="!quoteMode && !layawayMode"[^>]*@click="enterLayawayMode\(\)"[^>]*>/)[0];
const saveButtons = [...html.matchAll(/<button[^>]*@click="createLayaway\(\)"[^>]*>/g)].map(match => match[0]);
const attribute = (button, name) => button.match(new RegExp(`(?:^|\\s)${name}="([^"]*)"`))?.[1];
const evaluate = (button, name, pos) => new Function('scope', `with (scope) { return (${attribute(button, name)}); }`)(pos);

(async () => {
    const pos = fresh();
    assert.equal(pos.canCreateLayaway, true); // Actual permission serialized by Blade.
    assert.equal(pos.layawayMode, false);
    assert.equal(pos.cart.length, 0);
    assert.equal(evaluate(enterButton, 'x-show', pos), true);
    assert.equal(evaluate(enterButton, ':disabled', pos), false);
    assert.ok(!/\sdisabled(?:\s|=|>)/.test(enterButton));
    assert.equal(attribute(enterButton, 'href'), undefined);
    assert.ok(!html.includes('/apartados/crear'));
    const classes = attribute(enterButton, 'class').split(/\s+/);
    for (const required of ['cursor-pointer', 'min-h-[44px]']) assert.ok(classes.includes(required));
    assert.ok(!classes.some(name => /^(opacity-|pointer-events-none|hidden$)/.test(name)));
    await evaluate(enterButton, '@click', pos);
    assert.equal(pos.layawayMode, true);
    assert.equal(evaluate(enterButton, 'x-show', pos), false);
    for (const button of saveButtons) {
        assert.equal(evaluate(button, 'x-show', pos), true);
        assert.equal(evaluate(button, ':disabled', pos), true);
        assert.ok(attribute(button, 'x-text').includes('Crear apartado'));
    }
    assert.equal(saveButtons.length, 2);
    assert.ok(pos.layaway.expires_at.length >= 10);
    assert.equal(pos.cart.length, 0);
    await pos.createLayaway();
    assert.match(pos.notice, /Agregue al menos un producto/);
    assert.equal(requests.length, 0);
    assert.equal(pos.creatingLayaway, false);

    const transitions = fresh();
    await transitions.enterLayawayMode();
    assert.equal(transitions.layawayMode, true);
    assert.equal(transitions.quoteMode, false);
    transitions.addProduct(stocked);
    await transitions.leaveLayawayMode();
    assert.equal(transitions.layawayMode, false);

    browserWindow.location.search = '?mode=layaway';
    const requested = fresh();
    requested.init();
    assert.equal(requested.layawayMode, true);
    assert.equal(requested.quoteMode, false);
    assert.ok(requested.layaway.expires_at.length >= 10);
    browserWindow.location.search = '';
    assert.equal(transitions.quoteMode, false);
    assert.equal(transitions.cart.length, 1);
    await transitions.enterQuoteMode();
    assert.equal(transitions.quoteMode, true);
    assert.equal(transitions.layawayMode, false);
    await transitions.leaveQuoteMode();
    assert.equal(transitions.quoteMode, false);
    assert.equal(transitions.layawayMode, false);
    await transitions.enterQuoteMode();
    await transitions.enterLayawayMode();
    assert.equal(transitions.quoteMode, false);
    assert.equal(transitions.layawayMode, true);
    await transitions.enterQuoteMode();
    assert.equal(transitions.quoteMode, true);
    assert.equal(transitions.layawayMode, false);

    // Stock rules stay in force in apartado mode (unlike quotes): zero stock cannot be added.
    assert.ok(html.includes(':disabled="!quoteMode && !product.can_add_to_cart"'));
    pos.addProduct(product);
    assert.equal(pos.cart.length, 0);
    assert.match(pos.notice, /Sin existencia/);
    pos.addProduct(stocked);
    pos.addProduct(stocked);
    pos.increase(pos.cart[0]);
    assert.equal(pos.cart[0].quantity, 2);
    assert.equal(pos.exceedsStock(pos.cart[0]), false);
    pos.cart[0].quantity = 3;
    assert.equal(pos.exceedsStock(pos.cart[0]), true);
    assert.equal(pos.canCheckout, false); // Sale checkout is out of scope inside apartado mode.
    pos.openCheckout();
    assert.equal(pos.checkout.open, false);
    assert.equal(pos.canSubmitLayaway, false); // Still missing customer, prima and method.
    pos.cart[0].quantity = 2;
    assert.equal(pos.apartadoGrandTotal, 2000);
    assert.equal(pos.apartadoSubtotal, 2000);
    assert.equal(pos.apartadoTaxTotal, 0);
    assert.equal(pos.apartadoBalance, 2000);
    // Apartado respects the POS manual price permission, but discounts remain disabled
    // because current layaway records cannot preserve discount fields.
    pos.canOverridePrice = true;
    pos.cart[0]._unitPrice = '100';
    pos.cart[0]._discount = '50';
    pos._generalDiscountInput = '25';
    assert.equal(pos.apartadoGrandTotal, 200);
    pos.customerId = 7;
    pos.selectedCustomer = { id: 7, name: 'Cliente' };
    pos.layaway.initial_amount = '50';
    const simpleMethod = pos.layawayPaymentMethods.find(method => !method.requires_reference && !(method.affects_cash && !pos.cashSessionId))
        || pos.layawayPaymentMethods.find(method => !method.requires_reference)
        || pos.layawayPaymentMethods[0];
    const simpleReference = simpleMethod.requires_reference ? 'REF-SIMPLE' : '';
    pos.layaway.payments = [{ method: String(simpleMethod.id), amount: '50', reference: simpleReference, notes: '' }];
    assert.equal(pos.apartadoBalance, 150);
    assert.equal(pos.canSubmitLayaway, true);
    for (const button of saveButtons) assert.equal(evaluate(button, ':disabled', pos), false);
    const expectedExpires = pos.layaway.expires_at;
    const expectedToken = pos.checkoutToken;
    handler = async (_, options) => options.method === 'POST'
        ? reply({ success: true, message: 'Apartado APT-1 creado correctamente.', layaway_id: 42, layaway_number: 'APT-1', total: '200.0000', paid_total: '50.0000', balance_due: '150.0000', show_url: '/apartados/42' }, 201)
        : reply([stocked]);
    await pos.createLayaway();
    const saved = requests.find(request => request.options.method === 'POST');
    assert.ok(saved.url.endsWith('/pos/apartado'));
    assert.deepEqual(JSON.parse(saved.options.body), {
        customer_id: 7, expires_at: expectedExpires,
        items: [{ product_id: 1, quantity: 2, unit_price: 100 }],
        initial_amount: 50,
        payments: [{ payment_method_id: simpleMethod.id, amount: 50, reference: simpleMethod.requires_reference ? 'REF-SIMPLE' : null, notes: null }],
        cash_session_id: pos.cashSessionId || null, client_token: expectedToken,
    });
    assert.equal(pos.layawayMode, false);
    assert.equal(pos.cart.length, 0);
    assert.equal(pos.customerId, null);
    assert.equal(pos.selectedCustomer, null);
    assert.equal(pos.checkout.payments.length, 0);
    assert.equal(pos.creatingLayaway, false);
    assert.match(pos.successMessage, /APT-1/);
    assert.equal(fresh().layawayMode, false); // A fresh page never restores mode.

    // Mojibake fix: the quote-mode switch label must be UTF-8 correct.
    assert.ok(html.includes('Cambiar a cotización'));
    assert.ok(!html.includes('cotizaciÃ³n'));

    // Cash method shows received/change and sends received_amount in payload.
    const cashMethod = (fresh().paymentMethods || []).find(method => method.allows_change);
    if (cashMethod) {
        const cashPos = fresh();
        await cashPos.enterLayawayMode();
        cashPos.addProduct(stocked);
        cashPos.customerId = 7;
        cashPos.selectedCustomer = { id: 7, name: 'Cliente' };
        cashPos.layaway.initial_amount = '50';
        cashPos.layaway.payments = [{ method: String(cashMethod.id), amount: '50', reference: cashMethod.requires_reference ? 'REF-CASH' : '', notes: '' }];
        cashPos.layaway.received_amount = '100';
        assert.equal(cashPos.selectedLayawayMethod?.id, cashMethod.id);
        assert.equal(cashPos.layawayChange, 50);
        assert.equal(cashPos.layawayReceivedError, false);
        handler = async (_, options) => options.method === 'POST'
            ? reply({ success: true, message: 'Apartado APT-2 creado.', layaway_id: 43, layaway_number: 'APT-2', total: '200.0000', paid_total: '50.0000', balance_due: '150.0000', show_url: '/apartados/43' }, 201)
            : reply([stocked]);
        await cashPos.createLayaway();
        const cashSaved = [...requests].reverse().find(request => request.options.method === 'POST' && request.url.endsWith('/pos/apartado'));
        assert.ok(cashSaved);
        const cashBody = JSON.parse(cashSaved.options.body);
        assert.equal(cashBody.initial_amount, 50);
        assert.equal(cashBody.payments.length, 1);
        assert.equal(cashBody.payments[0].payment_method_id, cashMethod.id);
        assert.equal(cashBody.payments[0].amount, 50);
        assert.equal(cashBody.received_amount, 100);
    }

    // Non-cash method hides received/change and omits received_amount from payload.
    const nonCashMethod = (fresh().paymentMethods || []).find(method => !method.allows_change && method.type !== 'credit' && method.type !== 'loyalty_points');
    if (nonCashMethod) {
        const cardPos = fresh();
        await cardPos.enterLayawayMode();
        cardPos.addProduct(stocked);
        cardPos.customerId = 7;
        cardPos.selectedCustomer = { id: 7, name: 'Cliente' };
        cardPos.layaway.initial_amount = '50';
        const cardReference = nonCashMethod.requires_reference ? 'REF-CARD' : '';
        cardPos.layaway.payments = [{ method: String(nonCashMethod.id), amount: '50', reference: cardReference, notes: '' }];
        assert.equal(cardPos.selectedLayawayMethod?.allows_change, false);
        assert.equal(cardPos.layawayChange, 0);
        handler = async (_, options) => options.method === 'POST'
            ? reply({ success: true, message: 'Apartado APT-3 creado.', layaway_id: 44, layaway_number: 'APT-3', total: '200.0000', paid_total: '50.0000', balance_due: '150.0000', show_url: '/apartados/44' }, 201)
            : reply([stocked]);
        await cardPos.createLayaway();
        const cardSaved = requests.find(request => request.options.method === 'POST' && request.url.endsWith('/pos/apartado') && JSON.parse(request.options.body).payments?.[0]?.payment_method_id === nonCashMethod.id);
        assert.ok(cardSaved);
        const cardBody = JSON.parse(cardSaved.options.body);
        assert.equal('received_amount' in cardBody, false);
        assert.equal(cardBody.payments[0].reference, nonCashMethod.requires_reference ? 'REF-CARD' : null);
    }

    // Mixed payments UI: exact sum, no repeated method and required reference gate the save button.
    const mixed = fresh();
    await mixed.enterLayawayMode();
    mixed.addProduct(stocked);
    mixed.customerId = 7;
    mixed.selectedCustomer = { id: 7, name: 'Cliente' };
    mixed.layaway.initial_amount = '100';
    const available = mixed.layawayPaymentMethods;
    const first = available[0];
    const second = available.find(method => method.id !== first.id) || first;
    mixed.layaway.payments = [
        { method: String(first.id), amount: '60', reference: first.requires_reference ? 'REF-1' : '', notes: '' },
        { method: String(second.id), amount: '40', reference: second.requires_reference ? 'REF-2' : '', notes: '' },
    ];
    assert.equal(mixed.canSubmitLayaway, true);
    mixed.layaway.payments[1].amount = '30';
    assert.equal(mixed.canSubmitLayaway, false); // Sum of payments must equal the prima exactly.
    mixed.layaway.payments[1].amount = '40';
    mixed.layaway.payments[1].method = mixed.layaway.payments[0].method;
    assert.equal(mixed.canSubmitLayaway, false); // Repeated payment method.
    mixed.layaway.payments[1].method = String(second.id);
    if (second.requires_reference) {
        mixed.layaway.payments[1].reference = '';
        assert.equal(mixed.canSubmitLayaway, false); // Reference required for this method.
        mixed.layaway.payments[1].reference = 'REF-2';
    }
    assert.equal(mixed.canSubmitLayaway, true);
    mixed.layaway.payments.push({ method: '', amount: '', reference: '', notes: '' });
    assert.equal(mixed.canSubmitLayaway, false); // Empty added row blocks the save.

    // Leaving the mode preserves the cart and re-enables sale-mode stock enforcement.
    const leaving = fresh();
    await leaving.enterLayawayMode();
    leaving.addProduct(stocked);
    leaving.cart[0].quantity = 5;
    assert.equal(leaving.exceedsStock(leaving.cart[0]), true);
    await leaving.leaveLayawayMode();
    assert.equal(leaving.layawayMode, false);
    assert.equal(leaving.cart[0].quantity, 5);
    assert.equal(leaving.exceedsStock(leaving.cart[0]), true);

    // Without permission the action is inert even if the mode flag is forced.
    const denied = fresh();
    const beforeDenied = requests.length;
    denied.canCreateLayaway = false;
    assert.equal(evaluate(enterButton, ':disabled', denied), true);
    await evaluate(enterButton, '@click', denied);
    assert.equal(denied.layawayMode, false);
    denied.layawayMode = true;
    denied.addProduct(stocked);
    denied.customerId = 7;
    denied.layaway.initial_amount = '100';
    denied.layaway.payments = [{ method: String(denied.layawayPaymentMethods[0].id), amount: '100', reference: denied.layawayPaymentMethods[0].requires_reference ? 'REF-DENIED' : '', notes: '' }];
    assert.equal(denied.canSubmitLayaway, false);
    await denied.createLayaway();
    assert.equal(requests.length, beforeDenied);
    browserWindow.location.search = '?mode=layaway';
    const deniedRequested = fresh();
    deniedRequested.canCreateLayaway = false;
    deniedRequested.init();
    assert.equal(deniedRequested.layawayMode, false);
    assert.equal(deniedRequested.quoteMode, false);
    browserWindow.location.search = '';

    // Mode changes repeat the search without quote_mode and never auto-add a barcode in apartado.
    pos.query = 'BARCODE';
    handler = async () => reply([{ ...stocked, matched_barcode: 'BARCODE' }]);
    await pos.enterLayawayMode();
    assert.equal(new URL(requests.at(-1).url).searchParams.has('quote_mode'), false);
    assert.equal(pos.cart.length, 0);
    console.log(`Layaway mode UI OK (${checks} assertions)`);
})().catch(error => { console.error(error); process.exitCode = 1; });
