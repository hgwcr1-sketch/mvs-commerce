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

// ── 1. Modal post-venta: resumen, cliente, total, fiscal, correo y acciones ──
const modalStart = blade.indexOf('<template x-if="checkout.result">');
const modalEnd = blade.indexOf('</footer>', modalStart);
const modal = blade.slice(modalStart, modalEnd > 0 ? modalEnd + 400 : modalStart + 6000);
check('modal post-venta existe', modal.length > 500);
check('vista previa reutiliza receipt_url en iframe', modal.includes('receiptSrc()') && modal.includes('<iframe') && blade.includes('checkout.result?.receipt_url'));
check('muestra cliente y total', modal.includes('checkout.result.customer_name') && modal.includes('money(checkout.result.total)'));
check('selector de formato 58/80/carta', modal.includes("setReceiptFormat('{{ $value }}')") && blade.includes('SaleReceiptService::FORMAT_LABELS'));
check('preview embebido sin autoimpresión', blade.includes('&embed=1'));
check('fiscal se reutiliza desde receipt.blade', receipt.includes('Clave electrónica:') && receipt.includes('Comprobante electrónico en proceso') && receipt.includes('qr_image'));
check('receipt.blade oculta acciones al embebir', receipt.includes('$embed ?? false') && fiscalInvoice.includes('$embed ?? false'));
check('modal no duplica datos fiscales', !modal.includes('checkout.result.fiscal.clave'));
check('correo precargado en el input editable', /<input[^>]+x-model="checkout\.receiptEmail"/.test(modal) && /type="email"/.test(modal));
check('acción enviar correo existe', modal.includes('Enviar comprobante por correo') && modal.includes('sendReceiptMail()'));
check('acción imprimir comprobante', modal.includes('Imprimir comprobante<') || /Imprimir comprobante<\/button>/.test(modal));
check('acción reimprimir', modal.includes('>Reimprimir</button>'));
check('acción Carta/PDF', modal.includes('Carta/PDF') && modal.includes('checkout.result.receipt_pdf_url'));
check('acción nueva venta', modal.includes('@click="newSale"'));
check('botón fiscal solo para accepted', /x-show="checkout\.result\.electronic_status === 'accepted'"/.test(modal));

// ── 2. Reutiliza rutas/servicios existentes (sin duplicar) ──
check('envío reutiliza pos.receipt.mail', blade.includes("route('pos.receipt.mail'") && blade.includes('sendReceiptMail'));
check('impresión reutiliza MVS Print (printCompletedSale)', blade.includes('printCompletedSale(checkout.result.sale_id)'));
check('imprimir usa primera impresión y reimprimir usa reprint', blade.includes('printCompletedSale(checkout.result.sale_id, false)') && blade.includes('printCompletedSale(checkout.result.sale_id, true)'));
check('PDF reutiliza pos.receipt.pdf del payload', modal.includes('checkout.result.receipt_pdf_url'));
check('no se inventa otro endpoint de correo', !/fetch\('[^']*correo[^']*'/.test(blade));

// ── 3. Enter → Nueva venta una sola vez, con sus exclusiones ──
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
check('Enter con modal de resultado visible ejecuta newSale', s.newSaleCalls === 1);
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
check('Enter con foco en enlace (PDF/comprobante) no dispara newSale', s.newSaleCalls === 0);

s = state({ checkout: { open: true, result: { sale_id: 1 }, processing: false } });
handler.call(s, event({ tagName: 'SPAN', closest: (selector) => selector.includes('data-post-sale') ? {} : null }));
check('Enter dentro de las acciones del modal no dispara newSale', s.newSaleCalls === 0);

s = state({ checkout: { open: true, result: { sale_id: 1 }, processing: false }, imageModal: { open: true } });
handler.call(s, event());
check('Enter con modal secundario abierto no dispara newSale', s.newSaleCalls === 0);

s = state({ checkout: { open: true, result: { sale_id: 1 }, processing: false } });
handler.call(s, event({ tagName: 'BUTTON' }));
check('Enter con foco en botón no dispara newSale', s.newSaleCalls === 0);

s = state({ checkout: { open: false, result: null, processing: false } });
handler.call(s, event());
check('Sin resultado Enter abre el cobro (flujo normal)', s.openCheckoutCalls === 1 && s.newSaleCalls === 0);

check('newSale devuelve el foco al POS', /newSale\(\) \{[\s\S]{0,1500}focusSearch\(\)/.test(blade));

// ── 4. Símbolo ₡ determinista (sin depender de CLDR) ──
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

// ── 5. Superficies POS sin ¢/ø y con ₡ (incluye térmico 58/80 y fiscal) ──
check('index.blade sin ¢', !blade.includes(CENT));
check('receipt.blade sin ¢', !receipt.includes(CENT));
check('fiscal-invoice sin ¢', !fiscalInvoice.includes(CENT));
check('receipt.blade usa ₡ en 58/80', (receipt.match(new RegExp(COLON, 'g')) || []).length > 10);
check('receipt.blade conserva 58mm y 80mm', receipt.includes('format-58mm') && receipt.includes('format-80mm'));
check('ticket ESC/POS declara colón UTF-8', escPos.includes("CURRENCY = '" + COLON + "'"));

if (failures > 0) {
    console.error(failures + ' comprobaciones fallidas');
    process.exit(1);
}
console.log('Modal post-venta POS: todas las comprobaciones pasaron');
