const fs = require('fs');
const path = require('path');

const blade = fs.readFileSync(path.join(__dirname, '..', '..', 'resources', 'views', 'pos', 'index.blade.php'), 'utf8');
let failures = 0;

function check(name, condition) {
    if (condition) {
        console.log('OK   ' + name);
    } else {
        failures += 1;
        console.log('FAIL ' + name);
    }
}

const match = blade.match(/handleGlobalEnter\(event\) \{([\s\S]*?)\n        \},/);
check('handleGlobalEnter existe en el blade real', Boolean(match));
if (!match) process.exit(1);

const body = match[1];
const handler = new Function('event', body);

function state(overrides = {}) {
    const base = {
        orderRequest: { open: false },
        checkout: { open: false, result: null, processing: false },
        quickCustomer: { open: false },
        customerPhone: { open: false },
        cameraScannerOpen: false,
        resultsOpen: false,
        customerResultsOpen: false,
        canCheckout: true,
        newSaleCalls: 0,
        openCheckoutCalls: 0,
        newSale() { this.newSaleCalls += 1; },
        openCheckout() { this.openCheckoutCalls += 1; },
    };

    return Object.assign(base, overrides);
}

function event(target = 'BODY', extra = {}) {
    return Object.assign({ defaultPrevented: false, repeat: false, prevented: false, target: { tagName: target }, preventDefault() { this.prevented = true; } }, extra);
}

// 1. checkout.result visible → newSale exactamente una vez
let s = state({ checkout: { open: false, result: { sale_id: 1 }, processing: false } });
let e = event();
handler.call(s, e);
check('Enter en result ejecuta newSale', s.newSaleCalls === 1);
check('Enter en result no abre checkout de nuevo', s.openCheckoutCalls === 0);
check('Enter en result llama preventDefault', e.prevented === true);

// 2. repetición de tecla (mantener presionado) → no duplica
s = state({ checkout: { open: false, result: { sale_id: 1 }, processing: false } });
handler.call(s, event('BODY', { repeat: true }));
check('Enter mantenido no repite newSale', s.newSaleCalls === 0);

// 3. modal de cobro abierto → no newSale
s = state({ checkout: { open: true, result: null, processing: false } });
handler.call(s, event());
check('Enter con checkout abierto no dispara newSale', s.newSaleCalls === 0 && s.openCheckoutCalls === 0);

// 4. otro modal abierto → no newSale
s = state({ orderRequest: { open: true }, checkout: { open: false, result: { sale_id: 1 }, processing: false } });
handler.call(s, event());
check('Enter con otro modal abierto no dispara newSale', s.newSaleCalls === 0);

// 5. input activo → no newSale
s = state({ checkout: { open: false, result: { sale_id: 1 }, processing: false } });
handler.call(s, event('INPUT'));
check('Enter con input activo no dispara newSale', s.newSaleCalls === 0);

// 6. acción manual (foco en botón) → no newSale
s = state({ checkout: { open: false, result: { sale_id: 1 }, processing: false } });
handler.call(s, event('BUTTON'));
check('Enter con foco en botón (acción manual) no dispara newSale', s.newSaleCalls === 0);

// 7. sin result y con carrito → abre checkout (regresión del flujo normal)
s = state({ checkout: { open: false, result: null, processing: false }, canCheckout: true });
handler.call(s, event());
check('Sin result Enter abre checkout (flujo normal)', s.openCheckoutCalls === 1 && s.newSaleCalls === 0);

// 8. botón fiscal solo para accepted + motor térmico existente
check('Botón Imprimir comprobante fiscal existe', blade.includes('Imprimir comprobante fiscal'));
check('Botón fiscal solo cuando electronic_status es accepted', /x-show="checkout\.result\.electronic_status === 'accepted'"/.test(blade));
check('Botón fiscal usa el flujo térmico existente', /Imprimir comprobante fiscal<\/button>/.test(blade) && blade.includes('printCompletedSale(checkout.result.sale_id)'));

if (failures > 0) {
    console.error(failures + ' comprobaciones fallidas');
    process.exit(1);
}

console.log('Todas las comprobaciones del cierre POS pasaron');
