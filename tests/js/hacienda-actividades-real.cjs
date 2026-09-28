const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');

/**
 * Respuesta REAL que devuelve /clientes/contribuyente, normalizada desde el
 * JSON que hoy responde Hacienda (actividades[] = estado, tipo, codigo,
 * descripcion; regimen = {codigo, descripcion}; situacion = {estado, ...}).
 */
const LOOKUP_OK = {
    status: 'found',
    name: 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA',
    type: '02',
    regime: 'Impuesto sobre las Utilidades',
    situation: 'Inscrito',
    activities: [
        { code: '960113', description: 'Actividades de contratado de servicios' },
        { code: '461010', description: 'Venta al por menor' },
    ],
};

const LOOKUP_SIN_ACTIVIDADES = {
    status: 'found',
    name: 'PERSONA SIN ACTIVIDAD',
    type: '01',
    regime: 'No tiene',
    situation: 'No inscrito',
    activities: [],
};

function checkboxesDe(node, soloMarcados) {
    const out = [];
    const visitar = (n) => {
        (n.children || []).forEach((c) => {
            if (c.tagName === 'input' && c.type === 'checkbox' && (!soloMarcados || c.checked)) out.push(c);
            visitar(c);
        });
    };
    visitar(node);
    return out;
}

function crearElemento(tag) {
    return {
        tagName: tag,
        children: [],
        dataset: {},
        style: {},
        hidden: false,
        checked: false,
        value: '',
        name: '',
        id: '',
        type: '',
        className: '',
        textContent: '',
        innerHTML: '',
        listeners: {},
        addEventListener(evt, fn) { (this.listeners[evt] = this.listeners[evt] || []).push(fn); },
        dispatch(evt) { (this.listeners[evt] || []).forEach((fn) => fn.call(this, { target: this })); },
        setAttribute(k, v) { this[k] = v; },
        getAttribute(k) { return this[k]; },
        appendChild(child) { this.children.push(child); return child; },
        querySelectorAll(sel) { return checkboxesDe(this, sel.includes(':checked')); },
    };
}

/** Reproduce el marcado real que renderiza clientes/_form.blade.php. */
function crearDom() {
    const ids = [
        'identification_type', 'identification', 'identification_status',
        'name', 'taxpayer_name', 'taxpayer_proposal', 'taxpayer_meta',
        'taxpayer_activities_box', 'taxpayer_activities_list',
        'taxpayer_activities_empty', 'taxpayer_activities_inputs',
        'taxpayer_apply_all', 'taxpayer_clear',
    ];
    const elementos = {};
    ids.forEach((id) => {
        elementos[id] = crearElemento(id.startsWith('taxpayer_') ? 'div' : 'input');
        elementos[id].id = id;
    });
    elementos.identification_type.tagName = 'select';
    elementos.taxpayer_proposal_seed = crearElemento('script');
    elementos.taxpayer_proposal_seed.textContent = '[]';

    return {
        readyState: 'complete',
        getElementById(id) { return elementos[id] || null; },
        createElement(tag) { return crearElemento(tag); },
        querySelectorAll(sel) { return elementos.taxpayer_activities_list.querySelectorAll(sel); },
        addEventListener() {},
        _elementos: elementos,
    };
}

function cargarModulo(dom, fetchMock) {
    let codigo = fs.readFileSync(path.join(root, 'resources/js/modules/identificacion.js'), 'utf8');
    codigo = codigo.replace(/^\s*(export|import)\s.*$/gm, '');

    const sandbox = { document: dom, window: {}, fetch: fetchMock, setTimeout, clearTimeout, JSON, console };
    sandbox.window.document = dom;
    vm.runInNewContext(codigo, sandbox);
    return sandbox.window;
}

const dormir = (ms) => new Promise((r) => setTimeout(r, ms));


(async function ejecutar() {
    // 0) REPRODUCCIÓN DEL FALLO REAL: el formulario recién abierto tiene el
    // tipo de identificación en "". Antes de la corrección, escribir una
    // cédula válida NO disparaba ninguna consulta y no aparecía nada.
    let solicitadoInferido = null;
    const dom0 = crearDom();
    cargarModulo(dom0, async (url) => {
        solicitadoInferido = url;
        return { ok: true, status: 200, json: async () => LOOKUP_OK };
    });
    dom0._elementos.identification_type.value = '';
    dom0._elementos.identification.value = '109870988';
    dom0._elementos.identification.dispatch('input');
    await dormir(700);

    assert.equal(solicitadoInferido, '/clientes/contribuyente?tipo=01&identificacion=109870988',
        'Con el tipo vacío, escribir 9 dígitos debe consultar como 01 (cédula física)');
    assert.equal(dom0._elementos.taxpayer_proposal.hidden, false, 'La propuesta debe verse sin tocar el desplegable de tipo');
    assert.equal(dom0._elementos.taxpayer_activities_list.children.length, 2, 'Las actividades deben listarse sin elegir tipo');
    assert.equal(dom0._elementos.identification_type.value, '01',
        'El tipo deducido debe reflejarse en el select para que se guarde el mismo que se consultó');
    assert.equal(dom0._elementos.identification.value, '1-0987-0988', 'La máscara oficial debe aplicarse al deducir el tipo');

    // Un tipo ya elegido por la persona NUNCA se sobreescribe.
    dom0._elementos.identification_type.value = '02';
    dom0._elementos.identification.value = '';
    dom0._elementos.identification.dispatch('input');
    await dormir(700);
    assert.equal(dom0._elementos.identification_type.value, '02', 'El tipo elegido por la persona se respeta');

    // 1) Cliente nuevo: actividades visibles y enviadas al guardar.
    let solicitado = null;
    const dom1 = crearDom();
    cargarModulo(dom1, async (url) => {
        solicitado = url;
        return { ok: true, status: 200, json: async () => LOOKUP_OK };
    });

    dom1._elementos.identification_type.value = '02';
    dom1._elementos.identification.value = '3-101-000000';
    dom1._elementos.identification.dispatch('input');
    await dormir(700);

    assert.equal(solicitado, '/clientes/contribuyente?tipo=02&identificacion=3101000000', 'URL de consulta incorrecta');
    assert.equal(dom1._elementos.taxpayer_proposal.hidden, false, 'La propuesta debe quedar visible');
    assert.equal(dom1._elementos.taxpayer_activities_box.hidden, false, 'La caja de actividades debe quedar visible');
    assert.equal(dom1._elementos.taxpayer_activities_list.children.length, 2, 'Deben listarse 2 actividades');

    const rotulo = dom1._elementos.taxpayer_activities_list.children[0].children[1].textContent;
    assert.ok(rotulo.includes('960113'), 'La etiqueta debe mostrar el codigo oficial');
    assert.ok(rotulo.includes('Actividades de contratado'), 'La etiqueta debe mostrar la descripcion oficial');

    const ocultos = dom1._elementos.taxpayer_activities_inputs.children;
    assert.deepEqual(ocultos.map((i) => i.name), [
        'taxpayer_activities[][code]', 'taxpayer_activities[][description]',
        'taxpayer_activities[][code]', 'taxpayer_activities[][description]',
    ], 'Los inputs ocultos enviados al guardar son incorrectos');
    assert.deepEqual(ocultos.map((i) => i.value), [
        '960113', 'Actividades de contratado de servicios', '461010', 'Venta al por menor',
    ], 'Los valores enviados al guardar son incorrectos');

    // 2) Cliente sin actividades: no inventa ninguna.
    const dom2 = crearDom();
    cargarModulo(dom2, async () => ({ ok: true, status: 200, json: async () => LOOKUP_SIN_ACTIVIDADES }));
    dom2._elementos.identification_type.value = '01';
    dom2._elementos.identification.value = '1-0987-0988';
    dom2._elementos.identification.dispatch('input');
    await dormir(700);
    assert.equal(dom2._elementos.taxpayer_activities_list.children.length, 0, 'No debe inventar actividades');
    assert.equal(dom2._elementos.taxpayer_activities_box.hidden, false,
        'La sección de actividades debe seguir VISIBLE aunque no haya ninguna');
    assert.equal(dom2._elementos.taxpayer_activities_empty.hidden, false,
        'Debe decir explícitamente que Hacienda no informó actividades');
    assert.match(dom2._elementos.taxpayer_activities_empty.textContent, /no informó actividades/);
    assert.equal(dom2._elementos.taxpayer_activities_inputs.children.length, 0, 'No debe enviar actividades');
    assert.equal(dom2._elementos.taxpayer_proposal.hidden, false, 'El nombre oficial debe seguir mostrandose');

    // 3) Fallo de Hacienda: no bloquea el formulario.
    const dom3 = crearDom();
    cargarModulo(dom3, async () => { throw new Error('sin red'); });
    dom3._elementos.identification_type.value = '01';
    dom3._elementos.identification.value = '1-0987-0988';
    dom3._elementos.identification.dispatch('input');
    await dormir(700);
    assert.equal(dom3._elementos.taxpayer_activities_list.children.length, 0, 'Sin red no hay actividades');
    assert.equal(dom3._elementos.identification_status.textContent, 'No fue posible consultar Hacienda. Puede continuar.');

    console.log('Hacienda actividades UI OK');
})().catch((e) => { console.error('FALLO:', e.message); process.exit(1); });

