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
const paymentButton = html.match(/<button[^>]*@click="selectPaymentMethod\(method\)"[^>]*>/)[0];
const attribute = (button, name) => button.match(new RegExp(`(?:^|\\s)${name}="([^"]*)"`))?.[1];
const evaluate = (button, name, pos, method) => new Function('scope', `with (scope) { return (${attribute(button, name)}); }`)({ ...pos, method });
const classesOf = (pos, method) => pos.paymentMethodClasses(method);

const methods = [
    { id: 1, name: 'Efectivo', type: 'cash', allows_change: true, requires_reference: false },
    { id: 2, name: 'Tarjeta', type: 'card', allows_change: false, requires_reference: true },
    { id: 3, name: 'SINPE', type: 'sinpe', allows_change: false, requires_reference: true },
    { id: 4, name: 'Crédito', type: 'credit', allows_change: false, requires_reference: false },
];
const product = { id: 99, name: 'Producto', sale_price: 42827, tax_rate: 0, controls_inventory: false, can_add_to_cart: true, available_stock: 999 };

const withCart = () => {
    const pos = fresh();
    pos.paymentMethods = methods;
    pos.addProduct(product);
    return pos;
};

(async () => {
    assert.ok(paymentButton);
    assert.ok(attribute(paymentButton, ':disabled'));
    assert.match(attribute(paymentButton, ':disabled'), /paymentMethodDisabled/);
    assert.ok(attribute(paymentButton, ':class'));
    assert.match(attribute(paymentButton, ':class'), /paymentMethodClasses/);
    assert.ok(/\bmin-h-24\b/.test(attribute(paymentButton, 'class')));
    assert.ok(/cursor-pointer/.test(attribute(paymentButton, 'class')));
    assert.ok(/disabled:cursor-not-allowed/.test(attribute(paymentButton, 'class')));
    assert.ok(/disabled:bg-slate-100/.test(attribute(paymentButton, 'class')));
    assert.ok(/disabled:opacity-100/.test(attribute(paymentButton, 'class')));

    {
        const pos = withCart();
        assert.equal(pos.pendingBalance > 0, true);
        assert.equal(pos.checkout.open, false);
        assert.equal(evaluate(paymentButton, ':disabled', pos, methods[0]), false);
        assert.equal(evaluate(paymentButton, ':class', pos, methods[0]), 'border-primary bg-primary text-black hover:bg-primary-hover hover:border-primary');
        const gold = classesOf(pos, methods[0]);
        assert.equal(gold.button, 'border-primary bg-primary text-black hover:bg-primary-hover hover:border-primary');
        assert.equal(gold.badge, 'bg-white text-primary');
        assert.equal(gold.caption, 'text-black/85');
        for (const method of methods.slice(0, 3)) {
            assert.equal(evaluate(paymentButton, ':disabled', pos, method), false);
            assert.match(evaluate(paymentButton, ':class', pos, method), /border-primary bg-primary text-black/);
        }
    }

    {
        const pos = withCart();
        pos.checkout.payments = [{ payment_method_id: methods[0].id, method_type: 'cash', amount: pos.pendingBalance, received_amount: pos.pendingBalance, change_amount: 0 }];
        assert.equal(pos.pendingBalance, 0);
        for (const method of methods) {
            assert.equal(evaluate(paymentButton, ':disabled', pos, method), true);
            assert.equal(evaluate(paymentButton, ':class', pos, method), 'border-slate-300 bg-slate-100 text-slate-500');
            const slate = classesOf(pos, method);
            assert.equal(slate.button, 'border-slate-300 bg-slate-100 text-slate-500');
            assert.equal(slate.badge, 'bg-slate-200 text-slate-500');
            assert.equal(slate.caption, 'text-slate-500');
        }
        assert.equal(pos.checkoutCanConfirm, true);
    }

    {
        const pos = withCart();
        const partial = 20000;
        pos.checkout.payments = [{ payment_method_id: methods[0].id, method_type: 'cash', amount: partial, received_amount: partial, change_amount: 0 }];
        assert.ok(pos.pendingBalance > 0);
        assert.equal(evaluate(paymentButton, ':disabled', pos, methods[0]), true);
        assert.equal(evaluate(paymentButton, ':class', pos, methods[0]), 'border-slate-300 bg-slate-100 text-slate-500');
        for (const method of methods.slice(1, 3)) {
            assert.equal(evaluate(paymentButton, ':disabled', pos, method), false);
            assert.match(evaluate(paymentButton, ':class', pos, method), /border-primary bg-primary text-black/);
        }
    }

    {
        const pos = withCart();
        pos.checkout.payments = [{ payment_method_id: methods[0].id, method_type: 'cash', amount: pos.pendingBalance, received_amount: pos.pendingBalance, change_amount: 0 }];
        assert.equal(pos.pendingBalance, 0);
        assert.equal(pos.checkoutCanConfirm, true);
        pos.checkout.payments = [];
        assert.ok(pos.pendingBalance > 0);
        for (const method of methods.slice(0, 3)) {
            assert.equal(evaluate(paymentButton, ':disabled', pos, method), false);
        }
    }

    {
        const pos = withCart();
        pos.checkout.processing = true;
        for (const method of methods) {
            assert.equal(evaluate(paymentButton, ':disabled', pos, method), true);
            assert.equal(evaluate(paymentButton, ':class', pos, method), 'border-slate-300 bg-slate-100 text-slate-500');
        }
        pos.checkout.processing = false;
        assert.equal(evaluate(paymentButton, ':disabled', pos, methods[0]), false);
    }

    {
        const pos = withCart();
        assert.equal(pos.creditEligible, false);
        assert.equal(evaluate(paymentButton, ':disabled', pos, methods[3]), true);
        assert.equal(evaluate(paymentButton, ':class', pos, methods[3]), 'border-slate-300 bg-slate-100 text-slate-500');
        pos.selectedCustomer = { id: 1, name: 'Cliente', credit_limit: 100000, credit_used: 0, credit_days: 30 };
        assert.equal(pos.creditEligible, true);
        assert.equal(evaluate(paymentButton, ':disabled', pos, methods[3]), false);
        assert.match(evaluate(paymentButton, ':class', pos, methods[3]), /border-primary bg-primary text-black/);
    }

    {
        const pos = withCart();
        pos.selectedCustomer = { id: 1, name: 'Cliente', credit_limit: 100000, credit_used: 0, credit_days: 30 };
        pos.checkout.payments = [{ payment_method_id: methods[0].id, method_type: 'cash', amount: 20000, received_amount: 20000, change_amount: 0 }];
        assert.ok(pos.pendingBalance > 0);
        assert.equal(evaluate(paymentButton, ':disabled', pos, methods[3]), true);
        assert.match(evaluate(paymentButton, ':class', pos, methods[3]), /border-slate-300 bg-slate-100/);
    }

    {
        const pos = withCart();
        pos.checkout.draft.methodId = methods[1].id;
        assert.equal(evaluate(paymentButton, ':disabled', pos, methods[1]), false);
        assert.match(evaluate(paymentButton, ':class', pos, methods[1]), /ring-primary/);
        assert.match(evaluate(paymentButton, ':class', pos, methods[1]), /border-primary bg-primary text-black/);
        assert.equal(evaluate(paymentButton, ':class', pos, methods[2]), 'border-primary bg-primary text-black hover:bg-primary-hover hover:border-primary');
    }

    console.log(`Payment method buttons UI OK (${checks} assertions)`);
})().catch(error => { console.error(error); process.exitCode = 1; });