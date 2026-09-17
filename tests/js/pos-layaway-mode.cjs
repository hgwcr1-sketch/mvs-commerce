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
    for (const required of ['bg-primary', 'text-black', 'cursor-pointer', 'min-h-[44px]']) assert.ok(classes.includes(required));
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
    pos.layaway.payment_method_id = 3;
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
        initial_amount: 50, payment_method_id: 3, cash_session_id: pos.cashSessionId || null, reference: null, client_token: expectedToken,
    });
    assert.equal(pos.layawayMode, false);
    assert.equal(pos.cart.length, 0);
    assert.equal(pos.customerId, null);
    assert.equal(pos.selectedCustomer, null);
    assert.equal(pos.checkout.payments.length, 0);
    assert.equal(pos.creatingLayaway, false);
    assert.match(pos.successMessage, /APT-1/);
    assert.equal(fresh().layawayMode, false); // A fresh page never restores mode.

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
    denied.layaway.payment_method_id = 3;
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
