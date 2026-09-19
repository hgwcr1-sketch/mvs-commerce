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
vm.runInNewContext(script, {
    document: { addEventListener: (_, fn) => fn(), querySelector: () => ({ content: 'test-csrf' }) },
    Alpine: { data: (_, fn) => { factory = fn; } }, URL, URLSearchParams, console,
    window: { location: { origin: 'http://localhost', search: '' }, confirm: () => true },
    fetch: async (url, options = {}) => { requests.push({ url: String(url), options }); return handler(url, options); },
});
const fresh = () => {
    const pos = factory();
    pos.$nextTick = () => {};
    pos.$refs = {};
    return pos;
};
const product = { id: 1, name: 'Sin stock', sale_price: 1000, tax_rate: 13, controls_inventory: true, available_stock: 0, can_add_to_cart: false };
const enterButton = html.match(/<button[^>]*@click="enterQuoteMode\(\)"[^>]*>/)[0];
const saveButtons = [...html.matchAll(/<button[^>]*@click="quoteId \? updateQuote\(\) : createQuote\(\)"[^>]*>/g)].map(match => match[0]);
const attribute = (button, name) => button.match(new RegExp(`(?:^|\\s)${name}="([^"]*)"`))?.[1];
const evaluate = (button, name, pos) => new Function('scope', `with (scope) { return (${attribute(button, name)}); }`)(pos);

(async () => {
    const pos = fresh();
    assert.equal(pos.canCreateQuote, true); // Actual permission serialized by Blade.
    assert.equal(pos.quoteMode, false);
    assert.equal(pos.cart.length, 0);
    assert.equal(evaluate(enterButton, 'x-show', pos), true);
    assert.equal(evaluate(enterButton, ':disabled', pos), false);
    assert.ok(!/\sdisabled(?:\s|=|>)/.test(enterButton));
    const classes = attribute(enterButton, 'class').split(/\s+/);
    for (const required of ['bg-white', 'text-slate-700', 'cursor-pointer', 'min-h-[44px]']) assert.ok(classes.includes(required));
    assert.ok(!classes.some(name => /^(opacity-|pointer-events-none|hidden$)/.test(name)));
    await evaluate(enterButton, '@click', pos);
    assert.equal(pos.quoteMode, true);
    assert.equal(evaluate(enterButton, 'x-show', pos), false);
    for (const button of saveButtons) {
        assert.equal(evaluate(button, 'x-show', pos), true);
        assert.equal(evaluate(button, ':disabled', pos), true);
    }
    assert.equal(pos.cart.length, 0);
    await pos.createQuote();
    assert.match(pos.notice, /Agregue al menos un producto/);
    assert.equal(requests.length, 0);
    assert.equal(pos.creatingQuote, false);
    // The rendered action supports empty-cart entry; product button follows the same stock contract.
    assert.ok(!enterButton.includes('cart.length'));
    assert.ok(html.includes(':disabled="!quoteMode && !product.can_add_to_cart"'));
    assert.equal((html.match(/@click="quoteId \? updateQuote\(\) : createQuote\(\)"/g) || []).length, 2);

    pos.addProduct(product);
    for (const button of saveButtons) assert.equal(evaluate(button, ':disabled', pos), false);
    pos.addProduct(product);
    pos.increase(pos.cart[0]);
    assert.equal(pos.cart[0].quantity, 3);
    pos.cart[0].quantity = 10;
    assert.equal(pos.exceedsStock(pos.cart[0]), false);
    assert.equal(pos.canCheckout, false);
    pos.openCheckout();
    assert.equal(pos.checkout.open, false);
    await pos.confirmCheckout();
    assert.equal(requests.length, 0);
    await pos.leaveQuoteMode();
    assert.equal(pos.quoteMode, false);
    assert.equal(pos.cart[0].quantity, 10);
    assert.equal(pos.exceedsStock(pos.cart[0]), true);
    assert.equal(pos.canCheckout, false);
    pos.increase(pos.cart[0]);
    pos.addProduct(product);
    assert.equal(pos.cart[0].quantity, 10);

    const sale = fresh();
    sale.addProduct(product);
    assert.equal(sale.cart.length, 0);
    const stocked = { ...product, available_stock: 2, can_add_to_cart: true };
    sale.addProduct(stocked);
    sale.addProduct(stocked);
    sale.addProduct(stocked);
    sale.increase(sale.cart[0]);
    assert.equal(sale.cart[0].quantity, 2);
    assert.equal(sale.canCheckout, true);
    sale.cart[0].quantity = 3;
    assert.equal(sale.exceedsStock(sale.cart[0]), true);
    assert.equal(sale.canCheckout, false);
    const retainedCart = sale.cart;
    await sale.enterQuoteMode();
    assert.equal(sale.cart, retainedCart);
    sale.increase(sale.cart[0]);
    assert.equal(sale.cart[0].quantity, 4);
    assert.equal(fresh().quoteMode, false); // A fresh page never restores mode.

    const denied = fresh();
    denied.canCreateQuote = false;
    assert.equal(evaluate(enterButton, ':disabled', denied), true);
    await evaluate(enterButton, '@click', denied);
    assert.equal(denied.quoteMode, false);
    denied.quoteMode = true;
    denied.addProduct(product);
    await denied.createQuote();
    assert.equal(requests.length, 0);

    // Mode changes repeat the search without auto-adding a barcode, and invalidate stale responses.
    pos.query = 'BARCODE';
    handler = async () => reply([{ ...product, matched_barcode: 'BARCODE' }]);
    await pos.enterQuoteMode();
    assert.equal(new URL(requests.at(-1).url).searchParams.get('quote_mode'), '1');
    assert.equal(pos.cart[0].quantity, 10);
    await pos.leaveQuoteMode();
    assert.equal(new URL(requests.at(-1).url).searchParams.has('quote_mode'), false);
    assert.equal(pos.cart[0].quantity, 10);
    let finishOld;
    handler = () => new Promise(resolve => { finishOld = resolve; });
    const oldSearch = pos.enterQuoteMode();
    handler = async () => reply([stocked]);
    await pos.leaveQuoteMode();
    finishOld(reply([product]));
    await oldSearch;
    assert.equal(pos.results[0].can_add_to_cart, true);

    // Success reuses the quote endpoint and current customer/price/discount payload, then returns to sale.
    await pos.enterQuoteMode();
    pos.customerId = 17;
    pos.selectedCustomer = { id: 17, name: 'Cliente' };
    pos.documentType = 'electronic_invoice';
    pos.suspended.activeId = 42;
    pos.suspended.recoveryToken = 'recovered-cart';
    pos.cart[0]._unitPrice = '900';
    pos.cart[0]._discount = '10';
    pos.cart[0]._discountType = 'percentage';
    pos._generalDiscountInput = '50';
    handler = async (_, options) => options.method === 'POST'
        ? reply({ message: 'Cotización COT-1 creada correctamente.', quote_id: 99, show_url: '/cotizaciones/99' }, 201)
        : reply([stocked]);
    const oldToken = pos.checkoutToken;
    await pos.createQuote();
    const saved = requests.find(request => request.options.method === 'POST');
    assert.ok(saved.url.endsWith('/cotizaciones'));
    assert.deepEqual(JSON.parse(saved.options.body), {
        customer_id: 17, discount_total: 50, discount_total_type: 'fixed',
        items: [{ product_id: 1, quantity: 10, discount: 10, discount_type: 'percentage', unit_price: 900 }],
    });
    assert.equal(pos.quoteMode, true);
    assert.equal(pos.creatingQuote, false);
    assert.equal(pos.cart.length, 1);
    assert.equal(pos.quoteId, 99);
    assert.equal(pos.customerId, 17);
    assert.match(pos.notice, /COT-1/);

    // Update quote preserves same quote_id/quote_number and does not create a sale.
    handler = async (_, options) => options.method === 'PUT'
        ? reply({ message: 'Cotización COT-1 actualizada correctamente.', quote_id: 99, show_url: '/cotizaciones/99' })
        : reply([stocked]);
    await pos.updateQuote();
    const updated = requests.filter(request => request.options.method === 'PUT');
    assert.ok(updated.length > 0);
    assert.ok(updated.at(-1).url.endsWith('/cotizaciones/99'));
    assert.equal(pos.quoteId, 99);
    assert.equal(pos.quoteMode, true);
    assert.equal(pos.cart.length, 1);
    assert.match(pos.notice, /actualizada/);

    // Leave quote mode preserves cart and customer for sale conversion.
    await pos.leaveQuoteMode();
    assert.equal(pos.quoteMode, false);
    assert.equal(pos.cart.length, 1);
    assert.equal(pos.customerId, 17);
    assert.equal(pos.quoteId, 99);

    // Failed saves retain editable contents; double clicks and leaving during a save are ignored.
    await pos.enterQuoteMode();
    pos.addProduct(product);
    handler = async () => reply({ message: 'Error de validación' }, 422);
    await pos.createQuote();
    assert.equal(pos.quoteMode, true);
    assert.equal(pos.cart.length, 1);
    assert.equal(pos.creatingQuote, false);
    assert.equal(pos.notice, 'Error de validación');
    let finishSave;
    handler = () => new Promise(resolve => { finishSave = resolve; });
    const pendingSave = pos.createQuote();
    const requestCount = requests.length;
    await pos.createQuote();
    await pos.leaveQuoteMode();
    assert.equal(requests.length, requestCount);
    assert.equal(pos.quoteMode, true);
    finishSave(reply({ message: 'Guardada' }, 201));
    await pendingSave;

    // Loading an existing quote enters quote mode with editable cart.
    pos.quoteMode = false;
    pos.quoteId = null;
    handler = async () => reply({ quote_id: 7, quote_number: 'COT-7', customer_id: null, customer: null,
        items: [{ product_id: 1, name: 'Producto', quantity: 10, sale_price: 1000, unit_price: 1000,
            tax_rate: 13, discount_total: 0, available_stock: 2, controls_inventory: true }] });
    await pos.loadQuote(7);
    assert.equal(pos.quoteMode, true);
    assert.equal(pos.quoteId, 7);
    // In quote mode, exceedsStock is false (no stock enforcement while editing).
    assert.equal(pos.exceedsStock(pos.cart[0]), false);
    // canCheckout is false in quote mode regardless of stock.
    assert.equal(pos.canCheckout, false);
    await pos.enterQuoteMode();
    assert.equal(pos.quoteMode, true);
    console.log(`Quote mode UI OK (${checks} assertions)`);
})().catch(error => { console.error(error); process.exitCode = 1; });
