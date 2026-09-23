let checks = 0;
const assert = new Proxy(require('node:assert/strict'), {
    get: (target, key) => (...args) => { checks++; return target[key](...args); },
});
const fs = require('node:fs');
const vm = require('node:vm');
const html = fs.readFileSync(0, 'utf8'); // HTML renderizado de compras.create (página completa)

// ── Extraer los scripts inline de la página renderizada ──
const inlineScripts = [...html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)]
    .map(match => match[1]).filter(source => source.trim() !== '');

const guardScript = inlineScripts.find(source => source.includes('mvsOnBranchChange'));
assert.ok(guardScript, 'la guarda del selector de sucursal no está presente en el header');

const viewScript = inlineScripts.find(source => source.includes('mvs-branch-changed'));
assert.ok(viewScript, 'la vista de compra no escucha mvs-branch-changed');

assert.ok(html.includes('x-data="purchaseForm()"'), 'la vista no monta purchaseForm');
assert.ok(!guardScript.includes('location.reload'), 'la guarda todavía recarga la página');
assert.ok(!guardScript.includes('sendBeacon'), 'la guarda todavía navega vía sendBeacon');

// ── Simulación mínima de navegador: DOM, Alpine, fetch, sessionStorage ──
const formState = {
    purchaseDate: '2026-09-23',
    supplierInvoiceNumber: 'FAC-9',
    paymentType: 'credit',
    dueDate: '2026-10-23',
    purchaseNotes: 'Compra con observaciones',
    selectedSupplier: { id: 7, name: 'Proveedor Uno' },
    activeBranchId: null,
    items: [{
        id: 42, product_id: 42, name: 'Producto A', internal_code: 'PA',
        quantity: 5, unit_cost: 1250.5, new_sale_price: 1800, tax_rate: 13,
        allows_decimals: false,
    }],
    persistDraft() {},
};

const alpineRoots = [];
const Alpine = {
    $data: root => root.__alpineData || null,
};

function mountPurchaseForm() {
    const root = {
        __alpineData: Object.assign({}, formState, {
            $nextTick: () => {},
            $root: { dataset: {} },
        }),
    };
    alpineRoots.push(root);
    return root;
}

const select = {
    value: 1, // San Ramón
    disabled: false,
    attributes: { 'data-current-branch': '1' },
    setAttribute(name, value) { this.attributes[name] = value; },
    getAttribute(name) { return this.attributes[name] ?? null; },
};

const documentEvents = [];
const dispatchDocumentEvent = event => {
    documentEvents.filter(([name]) => name === event.type).forEach(([, fn]) => fn(event));
};
const sandboxDocument = {
    addEventListener: (name, fn) => documentEvents.push([name, fn]),
    querySelector: selector => (selector === 'meta[name="csrf-token"]' ? { content: 'test-csrf' } : null),
    querySelectorAll: selector => (selector.includes('purchaseForm()') ? alpineRoots : []),
    dispatchEvent: dispatchDocumentEvent,
};
sandboxDocument.CustomEvent = class { constructor(type, options) { this.type = type; this.detail = options?.detail; } };
// El navegador dispara alpine:init cuando Alpine arranca; aquí se dispara
// después de ejecutar todos los scripts inline, como en el navegador real.
const alpineInit = () => dispatchDocumentEvent({ type: 'alpine:init' });

const posts = [];
let branchPostHandler = async () => ({ ok: true, status: 200 });

const sandboxWindowEvents = [];
const sandbox = {
    document: sandboxDocument,
    Alpine,
    CustomEvent: sandboxDocument.CustomEvent,
    URL, URLSearchParams, console,
    navigator: {},
    window: {
        location: { pathname: '/compras/create', origin: 'http://localhost', href: '' },
        __mvsPurchaseBranchId: 1,
        __mvsKeepBranch: undefined,
        addEventListener: (name, fn) => sandboxWindowEvents.push([name, fn]),
    },
    fetch: async (url, options = {}) => {
        posts.push({ url: String(url), options });
        const response = await branchPostHandler(url, options);
        // El servidor solo actualiza la sesión cuando la respuesta es exitosa.
        const body = String(options.body || '');
        const match = body.match(/branch_id=(\d+)/);
        if (response.ok && match) sandbox.window.__mvsPurchaseBranchId = parseInt(match[1], 10);
        return response;
    },
    alert: () => { throw new Error('alert() invocado: el cambio de sucursal falló'); },
    sessionStorage: {
        store: new Map(),
        getItem(key) { return this.store.has(key) ? this.store.get(key) : null; },
        setItem(key, value) { this.store.set(key, String(value)); },
        removeItem(key) { this.store.delete(key); },
    },
};
sandbox.window.Alpine = Alpine;
sandbox.window.document = sandboxDocument;

// ── Ejecutar los scripts inline tal como el navegador los ejecuta ──
for (const source of inlineScripts) {
    vm.runInNewContext(source, sandbox, { filename: 'inline-script' });
}

assert.equal(sandbox.window.__mvsKeepBranch, true, 'la vista no activó el modo conservar sucursal');

// Alpine arranca: dispara alpine:init y la vista registra su listener.
alpineInit();
assert.ok(documentEvents.some(([name]) => name === 'mvs-branch-changed'), 'no hay listener de mvs-branch-changed');

// Montar el formulario Alpine de la vista (como lo haría Alpine en el navegador).
mountPurchaseForm();

// scope con el que se evalúan los atributos onchange inline
var sandboxWindow;

const makeChangeHandler = () => {
    // En el navegador real el onchange vive en el atributo HTML del select
    // #header-branch; se extrae ese bloque y se ejecuta igual que haría el
    // motor JS del navegador, con `event` en el scope del atributo.
    const block = html.match(/<select[^>]*header-branch[\s\S]*?<\/select>/)?.[0]
        ?? '';
    const onChange = block.match(/onchange="([^"]*)"/)?.[1]
        ?? 'null';
    assert.ok(onChange.includes('mvsOnBranchChange'), 'el selector header-branch no usa mvsOnBranchChange');
    return new Function('sandboxWindow', 'event', `with (sandboxWindow) { return (
        ${onChange}
    ); }`);
};

const runBranchChange = async from => {
    select.value = from;
    posts.length = 0;
    const event = { target: select, preventDefault: () => {} };
    await makeChangeHandler()(sandbox.window, event);
};

(async () => {
    // Estado digitado por el usuario con San Ramón activa.
    assert.equal(sandbox.window.__mvsPurchaseBranchId, 1);

    // ── San Ramón → Liberia ──
    await runBranchChange(2);
    assert.equal(posts.length, 1, 'no se envió el POST de sucursal');
    assert.ok(posts[0].url.includes('/sucursal-activa'), 'el POST no fue a branch.active.update');
    assert.equal(sandbox.window.__mvsPurchaseBranchId, 2, 'la sesión/sucursal no quedó en Liberia');
    assert.equal(String(select.attributes['data-current-branch']), '2');
    assert.equal(select.disabled, false, 'el selector quedó deshabilitado');
    assert.equal(alpineRoots[0].__alpineData.activeBranchId, 2, 'el formulario no tomó la nueva sucursal');

    // NADA se borró.
    const f = alpineRoots[0].__alpineData;
    assert.equal(f.items.length, 1);
    assert.equal(f.items[0].quantity, 5);
    assert.equal(f.items[0].unit_cost, 1250.5);
    assert.equal(f.items[0].new_sale_price, 1800);
    assert.equal(f.items[0].tax_rate, 13);
    assert.equal(f.selectedSupplier?.id, 7);
    assert.equal(f.purchaseDate, '2026-09-23');
    assert.equal(f.supplierInvoiceNumber, 'FAC-9');
    assert.equal(f.paymentType, 'credit');
    assert.equal(f.dueDate, '2026-10-23');
    assert.equal(f.purchaseNotes, 'Compra con observaciones');

    // ── Liberia → San Ramón ──
    await runBranchChange(1);
    assert.equal(sandbox.window.__mvsPurchaseBranchId, 1, 'no volvió a San Ramón');
    assert.equal(alpineRoots[0].__alpineData.activeBranchId, 1);
    assert.equal(alpineRoots[0].__alpineData.items.length, 1, 'los productos se perdieron en el segundo cambio');
    assert.equal(alpineRoots[0].__alpineData.items[0].quantity, 5);
    assert.equal(alpineRoots[0].__alpineData.selectedSupplier?.id, 7);
    assert.equal(alpineRoots[0].__alpineData.purchaseNotes, 'Compra con observaciones');

    // Error del servidor: el selector se revierte y avisa, sin recargar.
    branchPostHandler = async () => ({ ok: false, status: 500 });
    select.value = 2;
    posts.length = 0;
    let alerted = false;
    sandbox.alert = () => { alerted = true; };
    const sessionBeforeFailure = sandbox.window.__mvsPurchaseBranchId;
    await makeChangeHandler()(sandbox.window, { target: select, preventDefault: () => {} });
    assert.equal(alerted, true, 'no se avisó el fallo del cambio de sucursal');
    assert.equal(select.value, 1, 'el selector no se revirtió al valor previo');
    assert.equal(sandbox.window.__mvsPurchaseBranchId, sessionBeforeFailure, 'la sesión cambió a pesar del error');
    select.value = sessionBeforeFailure;

    // Guardar usa la sucursal vigente (módulo real compras.js).
    const comprasSource = fs.readFileSync('resources/js/modules/compras.js', 'utf8');
    assert.ok(comprasSource.includes("Alpine.data('purchaseForm'"), 'purchaseForm no encontrado');
    assert.ok(comprasSource.includes('window.__mvsPurchaseBranchId'), 'savePurchase no usa la sucursal vigente');
    assert.ok(!comprasSource.includes("location.reload"), 'compras.js recarga la página');

    console.log(`Compra: San Ramón -> Liberia -> San Ramón conserva TODO el formulario OK (${checks} aserciones)`);
})().catch(error => { console.error(error); process.exit(1); });
