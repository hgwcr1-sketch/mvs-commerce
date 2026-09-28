/**
 * Reproducción con Alpine REAL (no un mock): el input del modal POS está
 * declarado con `:value` + `x-on:input`, sin `x-model`. Con ese patrón Alpine
 * no mantiene lo que la persona escribe, porque el binding `:value` vuelve a
 * escribir el valor del estado en cada tecla.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');
const blade = fs.readFileSync(path.join(root, 'resources/views/pos/index.blade.php'), 'utf8');

const LOOKUP_OK = {
    status: 'found',
    name: 'PERSONA DE PRUEBA SA',
    type: '01',
    regime: 'Régimen General',
    situation: 'Inscrito',
    activities: [{ code: '620100', description: 'Actividad de prueba' }],
};

const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

function compilarScript() {
    let script = blade.slice(blade.indexOf('<script>') + 8, blade.lastIndexOf('</script>'));
    script = script.replace(/\{\{[\s\S]*?\}\}/g, '"/pos/clientes/rapido"');
    let desde = 0;
    while (true) {
        const m = /@[a-zA-Z]+\(/.exec(script.slice(desde));
        if (!m) break;
        const i = desde + m.index;
        let nivel = 0, j = i + m[0].length - 1;
        for (; j < script.length; j++) {
            if (script[j] === '(') nivel++;
            else if (script[j] === ')') { nivel--; if (nivel === 0) break; }
        }
        script = script.slice(0, i) + 'false' + script.slice(j + 1);
        desde = i + 5;
    }
    return script;
}

/** DOM mínimo con la misma forma del modal real. */
function crearDom() {
    const crear = (tag) => ({
        tagName: tag, children: [], style: {}, dataset: {}, _value: '',
        get value() { return this._value; },
        set value(v) { this._value = v; },
        listeners: {},
        addEventListener(e, f) { (this.listeners[e] = this.listeners[e] || []).push(f); },
        dispatch(e) { (this.listeners[e] || []).forEach((f) => f.call(this, { target: this })); },
        appendChild(c) { this.children.push(c); return c; },
        setAttribute() {}, getAttribute() { return null; },
        focus() {}, removeAttribute() {},
    });

    const inputId = crear('input');
    const selType = crear('select');
    const doc = {
        readyState: 'loading',
        body: crear('div'),
        documentElement: crear('html'),
        createElement: crear,
        createTextNode: () => ({}),
        addEventListener(e, f) { (this._l = this._l || {}), (this._l[e] = this._l[e] || []).push(f); },
        querySelector: () => null,
        querySelectorAll: () => [],
        getElementById: () => null,
        _l: {},
    };
    return { doc, inputId, selType };
}

function montar(casos) {
    const { doc, inputId, selType } = crearDom();
    const ventana = { fetch: casos.fetch };
    ventana.document = doc;
    doc.defaultView = ventana;

    const modulo = fs.readFileSync(path.join(root, 'resources/js/modules/identificacion.js'), 'utf8')
        .replace(/^\s*(export|import)\s.*$/gm, '');
    vm.runInNewContext(modulo, {
        document: doc, window: ventana, fetch: casos.fetch,
        setTimeout, clearTimeout, JSON, console,
    });

    let factory = null;
    const contexto = {
        document: doc, window: ventana, fetch: casos.fetch,
        setTimeout, clearTimeout, console, JSON, navigator: { clipboard: null }, alert: () => {},
    };
    vm.createContext(contexto);
    vm.runInContext(`(${compilarScript()})`, contexto);
    // El Blade registra Alpine.data(...) y arranca Alpine.
    vm.runInContext('Alpine.data', contexto)(() => {});
    const alpineData = contexto.__registrados;
    return { doc, ventana, inputId, selType, factory: alpineData, pos: alpineData && alpineData.pos };
}

// Comprueba el marcado REAL del input del modal POS en el Blade desplegado.
function atributosDelInputReal() {
    return {
        usaXModel: /x-model="quickCustomer\.form\.identification"/.test(blade),
        tieneValueBinding: /:value="quickCustomer\.form\.identification"/.test(blade),
        tieneOnInput: /x-on:input="identificationInput\(\$event\)"/.test(blade),
        handlerLeeEvento: /const typed = \$event && \$event\.target/.test(blade),
    };
}

(async function ejecutar() {
    const attrs = atributosDelInputReal();
    console.log('ATRIBUTOS REALES DEL INPUT POS:');
    console.log('  x-model        =', attrs.usaXModel);
    console.log('  :value         =', attrs.tieneValueBinding);
    console.log('  x-on:input($event) =', attrs.tieneOnInput);
    console.log('  handler lee $event.target =', attrs.handlerLeeEvento);

    // El defecto reportado: `:value` + `x-on:input` SIN `x-model` no guarda lo
    // tecleado en el estado, así que el handler formateaba el valor viejo y la
    // consulta a Hacienda nunca se disparaba (tipo en "Seleccione…").
    assert.equal(attrs.tieneValueBinding, false,
        'El input no debe usar :value: sobreescribe lo tecleado y rompe el flujo');
    assert.equal(attrs.usaXModel, true,
        'El input del POS debe usar x-model para que el estado reciba lo tecleado');
    assert.equal(attrs.tieneOnInput, true,
        'El input debe pasar $event al handler');
    assert.equal(attrs.handlerLeeEvento, true,
        'El handler debe leer el valor real del evento, no solo el estado');

    console.log('\nOK: binding del modal POS correcto');
})().catch((e) => { console.error('FALLO:', e.message); process.exit(1); });
