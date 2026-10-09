const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..', '..');
const blade = fs.readFileSync(path.join(root, 'resources', 'views', 'pos', 'index.blade.php'), 'utf8');
const receipt = fs.readFileSync(path.join(root, 'resources', 'views', 'pos', 'receipt.blade.php'), 'utf8');
const fiscalInvoice = fs.readFileSync(path.join(root, 'resources', 'views', 'pos', 'fiscal-invoice.blade.php'), 'utf8');
const escPos = fs.readFileSync(path.join(root, 'app', 'Services', 'MvsPrint', 'EscPosSaleTicket.php'), 'utf8');

let failures = 0;
function check(name, condition) {
    console.log((condition ? 'OK   ' : 'FAIL ') + name);
    if (!condition) failures += 1;
}

const COLON = '\u20A1';
const CENT = '\u00A2';

// ── 1. Cierre POS: recibo original directo (sin modal resumido ni iframe) ──
check('index.blade sin iframe', !blade.includes('<iframe'));
check('index.blade sin receiptSrc()', !blade.includes('receiptSrc()'));
check('index.blade sin selector de formato duplicado', !blade.includes('setReceiptFormat'));
check('index.blade sin correo duplicado del modal', !blade.includes('sendReceiptMail') && !blade.includes('post-sale-email'));
check('index.blade sin resumen modal post-venta', !blade.includes('Vista previa del comprobante') && !blade.includes('template x-if="checkout.result"'));
check('al completar navega al receipt original', blade.includes('window.location.href = payload.receipt_url'));
check('preserva MVS Print antes de navegar', blade.includes('await this.attemptAutoPrint(payload.sale_id)'));
check('impresion reutiliza MVS Print (printCompletedSale)', blade.includes('attemptAutoPrint(payload.sale_id)') && /printCompletedSale\(saleId, false\)/.test(blade));
check('no se inventa otro endpoint de correo', !/fetch\('[^']*correo[^']*'/.test(blade));

// ── 2. receipt.blade.php reutilizado como experiencia original del cierre ──
check('receipt.blade ofrece Nueva venta', receipt.includes('data-new-sale') && receipt.includes('Nueva venta'));
check('receipt.blade conserva correo editable', receipt.includes('Enviar comprobante') && receipt.includes('type="email"') && receipt.includes("route('pos.receipt.mail'"));
check('receipt.blade conserva impresion y formatos', receipt.includes('window.print()') && receipt.includes('format-58mm') && receipt.includes('format-80mm') && receipt.includes('format-letter'));
check('receipt.blade conserva PDF (Carta)', receipt.includes("route('pos.receipt.pdf'"));
check('receipt.blade conserva QR de fidelizacion', (receipt.match(new RegExp(COLON, 'g')) || []).length > 10 && receipt.includes('loyalty-table'));
check('receipt.blade conserva fiscal accepted/pending', receipt.includes('Clave electr\u00f3nica:') && receipt.includes('Comprobante electr\u00f3nico en proceso') && receipt.includes('qr_image'));
check('Enter en receipt: guardado contra duplicados', /keydown[\s\S]{0,700}event\.repeat/.test(receipt));
check('Enter en receipt no interfiere con el correo ni otros controles', /keydown[\s\S]{0,700}\['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON', 'A'\]\.includes\(tag\)/.test(receipt));
check('Enter en receipt navega al POS (nueva venta)', /keydown[\s\S]{0,900}window\.location\.href = /.test(receipt));

// ── 3. Enter en el POS → Nueva venta una sola vez, con sus exclusiones ──
const match = blade.match(/handleGlobalEnter\(event\) \{([\s\S]*?)\n        \},/);
check('handleGlobalEnter existe', Boolean(match));
if (!match) process.exit(1);
const handler = new Function('event', match[1]);

function state(overrides = {}) {
    return Object.assign({
        orderRequest: { open: false },
        checkout: { open: false, result: null, processing: false },
        quickCustomer: { open: false },
        customerPhone: { open: false },
        imageModal: { open: false },
        cameraScannerOpen: false,
        resultsOpen: false,
        customerResultsOpen: false,
        canCheckout: true,
        newSaleCalls: 0,
        openCheckoutCalls: 0,
        newSale() { this.newSaleCalls += 1; },
        openCheckout() { this.openCheckoutCalls += 1; },
    }, overrides);
}

function event(target = {}, extra = {}) {
    return Object.assign({ defaultPrevented: false, repeat: false, target: Object.assign({ tagName: 'BODY' }, target), preventDefault() { this.prevented = true; } }, extra);
}

let s = state({ checkout: { open: true, result: { sale_id: 1 }, processing: false } });
let e = event();
handler.call(s, e);
check('Enter con resultado visible ejecuta newSale', s.newSaleCalls === 1);
check('Enter llama preventDefault', e.prevented === true);

s = state({ checkout: { open: true, result: { sale_id: 1 }, processing: false } });
handler.call(s, event({}, { repeat: true }));
check('Enter mantenido (event.repeat) no repite', s.newSaleCalls === 0);

s = state({ checkout: { open: true, result: { sale_id: 1 }, processing: false } });
handler.call(s, event({ tagName: 'INPUT' }));
check('Enter escribiendo en el correo no dispara newSale', s.newSaleCalls === 0);

s = state({ checkout: { open: true, result: { sale_id: 1 }, processing: false } });
handler.call(s, event({ tagName: 'TEXTAREA', isContentEditable: true }));
check('Enter en contenteditable no dispara newSale', s.newSaleCalls === 0);

s = state({ checkout: { open: true, result: { sale_id: 1 }, processing: false } });
handler.call(s, event({ tagName: 'A' }));
check('Enter con foco en enlace no dispara newSale', s.newSaleCalls === 0);

s = state({ checkout: { open: true, result: { sale_id: 1 }, processing: false } });
handler.call(s, event({ tagName: 'BUTTON' }));
check('Enter con foco en boton no dispara newSale', s.newSaleCalls === 0);

s = state({ checkout: { open: false, result: null, processing: false } });
handler.call(s, event());
check('Sin resultado Enter abre el cobro (flujo normal)', s.openCheckoutCalls === 1 && s.newSaleCalls === 0);

check('newSale devuelve el foco al POS', /newSale\(\) \{[\s\S]{0,1500}focusSearch\(\)/.test(blade));

// ── 4. Simbolo ₡ determinista (sin depender de CLDR) ──
const crc = blade.match(/crcMoney\(value\) \{([\s\S]*?)\n        \},/);
check('crcMoney existe', Boolean(crc));
if (crc) {
    const crcMoney = new Function('value', crc[1]);
    check('crcMoney produce ₡ con 2 decimales', crcMoney(1234.5).indexOf(COLON) === 0 && /,\d{2}$/.test(crcMoney(1234.5)));
    check('crcMoney no produce signos sustitutos', !crcMoney(1234.5).includes(CENT) && !crcMoney(1234.5).includes('?'));
    check('crcMoney conserva signo negativo', crcMoney(-0.01).startsWith('-') && crcMoney(-0.01).includes(COLON));
    check('money() delega en crcMoney', /money\(value\) \{ return this\.crcMoney\(value\); \}/.test(blade));
    check('money2() delega en crcMoney', /money2\(value\) \{ return this\.crcMoney\(value\); \}/.test(blade));
}

// ── 5. Superficies POS sin ¢/ø y con ₡ (incluye termico 58/80 y fiscal) ──
check('index.blade sin ¢', !blade.includes(CENT));
check('receipt.blade sin ¢', !receipt.includes(CENT));
check('fiscal-invoice sin ¢', !fiscalInvoice.includes(CENT));
check('receipt.blade usa ₡ en 58/80', (receipt.match(new RegExp(COLON, 'g')) || []).length > 10);
check('receipt.blade conserva 58mm y 80mm', receipt.includes('format-58mm') && receipt.includes('format-80mm'));
check('ticket ESC/POS declara colon UTF-8', escPos.includes("CURRENCY = '" + COLON + "'"));

if (failures > 0) {
    console.error(failures + ' comprobaciones fallidas');
    process.exit(1);
}
console.log('Todas las comprobaciones pasaron');
