import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const handlers = {};
const initial = { value: '100.5000' };
globalThis.document = {
    addEventListener: (name, handler, capture) => {
        assert.equal(capture, true);
        handlers[name] = handler;
    },
    querySelectorAll: () => [initial],
};
const source = readFileSync(new URL('../../resources/js/modules/monetary-input.js', import.meta.url), 'utf8');
const { normalizeMoney, displayMoney } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
assert.equal(initial.value, '100.50');
for (const [input, expected] of [['370,87', '370.87'], ['370.87', '370.87'], ['0.01', '0.01'], ['100.50', '100.50'], ['500', '500.00']]) {
    const target = { value: input, matches: () => true };
    handlers.input({ target });
    assert.equal(target.value, normalizeMoney(input));
    handlers.blur({ target });
    assert.equal(target.value, expected);
}
assert.equal(displayMoney('0.1234'), '0.1234');
assert.equal(displayMoney('999999999999999.99'), '999999999999999.99');
assert.equal(displayMoney(''), '');
assert.equal(displayMoney('1,2,3'), '1,2,3');
const quantity = { value: '1,25', matches: () => false };
handlers.input({ target: quantity });
assert.equal(quantity.value, '1,25');
console.log('Monetary input: five required values, DOM handlers, precision, empty/invalid input and field isolation passed.');
