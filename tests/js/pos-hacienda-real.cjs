const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');
const blade = fs.readFileSync(path.join(root, 'resources/views/pos/index.blade.php'), 'utf8');

/**
 * JSON REAL que devuelve /clientes/contribuyente y consume el POS.
 * El flujo se ejecuta sobre el componente Alpine REAL extraído del Blade,
 * no sobre servicios aislados.
 */
const LOOKUP_OK = {
    status: 'found',
    name: 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA',
    type: '02',
    regime: 'Impuesto sobre las Utilidades',
    situation: 'Inscrito',
    activities: [{ code: '960113', description: 'Actividades de contratado de servicios' }],
};

const LOOKUP_SIN_ACTIVIDADES = {
    status: 'found', name: 'PERSONA SIN ACTIVIDAD', type: '01',
    regime: 'No tiene', situation: 'No inscrito', activities: [],
};

const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

function extraerFabrica(ventana) {
    let script = blade.slice(blade.indexOf('<script>') + 8, blade.lastIndexOf('</script>'));
    // Expresiones Blade que romperían el parseo fuera de Laravel.
    script = script.replace(/\{\{[\s\S]*?\}\}/g, '"/pos/clientes/rapido"');
    // Las directivas Blade @json(...), @js(...) y similares: par balanceado.
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
    let factory = null;
    // El registro de Alpine ocurre dentro de un listener: se dispara de inmediato.
    const doc = { readyState: 'loading', getElementById: () => null, addEventListener: (_, fn) => fn(), createElement: () => ({}), querySelector: () => ({ content: 'csrf' }), querySelectorAll: () => [] };
    try {
        vm.runInNewContext(script, {
            document: doc, window: ventana, Alpine: { data: (n, fn) => { if (n === 'posTerminal') factory = fn; }, start() {} },
            console, setTimeout, clearTimeout, fetch: ventana.fetch, navigator: { clipboard: null },
        });
    } catch (e) {
        const linea = /evalmachine[^:]*:(\d+)/.exec(e.stack);
        if (linea) console.error('SCRIPT LINEA ' + linea[1] + ': ' + script.split('\n')[Number(linea[1]) - 1]);
        throw e;
    }
    assert.ok(factory, 'No se pudo extraer el componente posTerminal');
    return { factory, doc };
}

function crearPos(fetchMock) {
    const ventana = { fetch: fetchMock };
    const doc = { readyState: 'complete', getElementById: () => null, addEventListener() {}, createElement: () => ({}), querySelector: () => ({ content: 'csrf' }), querySelectorAll: () => [] };
    ventana.document = doc;

    const modulo = fs.readFileSync(path.join(root, 'resources/js/modules/identificacion.js'), 'utf8')
        .replace(/^\s*(export|import)\s.*$/gm, '');
    vm.runInNewContext(modulo, {
        document: doc, window: ventana, fetch: fetchMock, setTimeout, clearTimeout, JSON, console,
    });

    // `fetch` global delegable: se define ANTES de extraer el componente para
    // que storeQuickCustomer() use siempre la implementación vigente.
    let fetchActual = fetchMock;
    const fetchDelegable = (...args) => fetchActual(...args);
    ventana.fetch = fetchDelegable;

    const { factory } = extraerFabrica(ventana);
    const pos = factory.call({
        document: doc, window: ventana, fetch: fetchDelegable, setTimeout, clearTimeout, console, JSON,
        navigator: { clipboard: null }, alert: () => {},
    });
    pos.$watch = () => {};
    pos.setFetch = (fn) => { fetchActual = fn; };
    return pos;
}

(async function ejecutar() {
    // 0) REPRODUCCIÓN EXACTA DEL USUARIO: modal POS, tipo en "Seleccione…" y
    // 109880401 tecleado. El evento debe llegar con el valor REAL tecleado.
    let solicitadoUsuario = null;
    const pos0 = crearPos(async (url) => {
        solicitadoUsuario = url;
        return { ok: true, status: 200, json: async () => LOOKUP_OK };
    });
    pos0.quickCustomer.form.identification_type = '';
    pos0.quickCustomer.form.identification = '';
    pos0.identificationInput({ target: { value: '109880401' } });
    await dormir(700);

    assert.equal(solicitadoUsuario, '/clientes/contribuyente?tipo=01&identificacion=109880401',
        'El POS debe consultar la identificación tecleada, no el valor viejo del estado');
    assert.equal(pos0.quickCustomer.form.identification_type, '01',
        'El POS debe deducir Cédula Física y rellenar el select');
    assert.equal(pos0.quickCustomer.form.identification, '1-0988-0401', 'El POS debe aplicar la máscara oficial');
    assert.equal(pos0.quickCustomer.ident.status, 'found', 'El POS debe mostrar el flujo de Hacienda');
    assert.equal(pos0.quickCustomer.ident.activities.length, 1, 'El POS debe mostrar la actividad económica');
    assert.equal(pos0.quickCustomer.form.name, 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA', 'El POS debe aplicar el nombre oficial');

    // 1) POS: cedula valida -> nombre oficial + actividades visibles y aplicadas.
    let solicitado = null;
    const pos = crearPos(async (url) => {
        solicitado = url;
        return { ok: true, status: 200, json: async () => LOOKUP_OK };
    });

    pos.quickCustomer.form.identification_type = '02';
    pos.quickCustomer.form.identification = '3101000000';
    pos.identificationInput();
    await dormir(700);

    assert.equal(solicitado, '/clientes/contribuyente?tipo=02&identificacion=3101000000', 'POS no consulta el endpoint');
    assert.equal(pos.quickCustomer.ident.status, 'found', 'POS no reconoce found');
    assert.equal(pos.quickCustomer.ident.name, 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA', 'POS no guarda el nombre oficial');
    assert.equal(pos.quickCustomer.form.name, 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA', 'POS no aplica el nombre oficial');
    assert.equal(pos.quickCustomer.ident.regime, 'Impuesto sobre las Utilidades', 'POS no guarda el regimen');
    assert.equal(pos.quickCustomer.ident.situation, 'Inscrito', 'POS no guarda la situacion');
    assert.equal(pos.quickCustomer.ident.activities.length, 1, 'POS no muestra la actividad economica');
    assert.equal(pos.quickCustomer.ident.activities[0].code, '960113', 'POS muestra un codigo incorrecto');
    assert.equal(pos.quickCustomer.ident.applied.length, 1, 'POS no deja la actividad aplicada antes de guardar');

    // 2) El payload REAL que el POS envía al guardar debe llevar code/description.
    const pos2 = crearPos(async () => ({ ok: true, status: 200, json: async () => LOOKUP_OK }));
    pos2.quickCustomer.form.identification_type = '02';
    pos2.quickCustomer.form.identification = '3101000000';
    pos2.identificationInput();
    await dormir(700);

    let cuerpo = null;
    const ctxFetch = async (url, opts) => {
        cuerpo = JSON.parse(opts.body);
        return { ok: true, status: 201, json: async () => ({ success: true, customer: { id: 1 } }) };
    };
    const fetchVacio = async (url, opts) => {
        cuerpoVacio = JSON.parse(opts.body);
        return { ok: true, status: 201, json: async () => ({ success: true, customer: { id: 1 } }) };
    };
    let cuerpoVacio = null;
    pos2.setFetch(ctxFetch);
    pos2.selectCustomer = () => {};
    pos2.readFetchResponse = async (r) => r;
    pos2.quickCustomer.form.name = 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA';
    await pos2.storeQuickCustomer();

    if (!cuerpo) {
        console.error('DEBUG name=' + JSON.stringify(pos2.quickCustomer.form.name) + ' saving=' + pos2.quickCustomer.saving);
    }
    assert.ok(cuerpo, 'El POS debe enviar el cliente al guardar');
    assert.equal(cuerpo.name, 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA', 'El POS debe enviar el nombre oficial');
    assert.deepEqual(cuerpo.taxpayer_activities, [
        { code: '960113', description: 'Actividades de contratado de servicios' },
    ], 'El payload del POS debe llevar las actividades aplicadas con code/description');

    // 3) Si el cajero quita la actividad, NO debe viajar en el payload.
    pos2.setFetch(fetchVacio);
    pos2.quickCustomer.form.name = 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA';
    pos2.quickCustomer.ident.applied = [];
    await pos2.storeQuickCustomer();
    assert.ok(cuerpoVacio, 'El POS debe volver a enviar el cliente');
    assert.equal(cuerpoVacio.taxpayer_activities, undefined, 'Sin actividades aplicadas no se debe enviar el campo');

    // 4) Contribuyente sin actividades: no inventa ninguna.
    const pos3 = crearPos(async () => ({ ok: true, status: 200, json: async () => LOOKUP_SIN_ACTIVIDADES }));
    pos3.quickCustomer.form.identification_type = '01';
    pos3.quickCustomer.form.identification = '109870988';
    pos3.identificationInput();
    await dormir(700);
    assert.equal(pos3.quickCustomer.ident.activities.length, 0, 'POS no debe inventar actividades');
    assert.equal(pos3.quickCustomer.ident.applied.length, 0, 'POS no debe aplicar actividades inexistentes');
    assert.equal(pos3.quickCustomer.ident.name, 'PERSONA SIN ACTIVIDAD', 'POS debe conservar el nombre oficial');

    console.log('POS Hacienda UI OK');
})().catch((e) => { console.error('FALLO:', e.message); process.exit(1); });
