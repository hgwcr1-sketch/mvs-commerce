/**
 * Identificación tributaria de Costa Rica: máscara, límite y consulta Hacienda.
 *
 * Fuente única de reglas en el frontend (el backend las centraliza en
 * App\Support\IdentificationRules):
 *   01 Cédula física   -> EXACTAMENTE 9 dígitos  -> 1-0987-0988 (máx. 11)
 *   02 Cédula jurídica -> EXACTAMENTE 10 dígitos -> 3-101-000000 (máx. 12)
 *   03 DIMEX           -> 11 o 12 dígitos, sin guiones (máx. 12)
 *   04 NITE            -> EXACTAMENTE 10 dígitos, sin guiones (máx. 10)
 *   05 Extranjero      -> máximo 20 caracteres alfanuméricos
 *
 * La máscara con guiones es solo visual: la consulta a Hacienda siempre
 * recibe el valor normalizado (solo dígitos).
 */

const RULES = {
    '01': { label: 'Cédula Física', digits: 9, groups: [1, 4, 4], example: '1-0987-0988', mask: true },
    '02': { label: 'Cédula Jurídica', digits: 10, groups: [1, 3, 6], example: '3-101-000000', mask: true },
    '03': { label: 'DIMEX', digits: [11, 12], groups: [], example: '11 o 12 dígitos', mask: false },
    '04': { label: 'NITE', digits: 10, groups: [], example: '10 dígitos', mask: false },
    '05': { label: 'Extranjero no domiciliado', digits: null, groups: [], example: 'Pasaporte u otra identificación', mask: false, maxLength: 20 },
};

const LEGACY_MAX = 50;
const LOOKUP_TYPES = ['01', '02', '03', '04'];

function rule(type) {
    return Object.prototype.hasOwnProperty.call(RULES, type) ? RULES[type] : null;
}

function countsOf(type) {
    const current = rule(type);
    if (!current || !current.digits) return null;
    return Array.isArray(current.digits) ? current.digits : [current.digits];
}

function digitsOf(value) {
    return String(value === null || value === undefined ? '' : value).replace(/\D+/g, '');
}

function isNumericLike(value) {
    return /^[0-9\-\.\s]*$/.test(String(value === null || value === undefined ? '' : value));
}

function maxDigitsOf(type) {
    const counts = countsOf(type);
    return counts ? Math.max.apply(null, counts) : null;
}

function maxLengthOf(type) {
    const current = rule(type);
    if (!current) return LEGACY_MAX;
    if (typeof current.maxLength === 'number') return current.maxLength;
    const maxDigits = maxDigitsOf(type);
    if (maxDigits === null) return LEGACY_MAX;
    return maxDigits + (current.groups.length ? current.groups.length - 1 : 0);
}

function placeholderOf(type) {
    const current = rule(type);
    return current ? current.example : 'Opcional si no tiene';
}

function format(type, value) {
    const raw = String(value === null || value === undefined ? '' : value);
    if (raw === '') return '';
    const current = rule(type);
    if (!current || !isNumericLike(raw)) return raw;

    const digits = digitsOf(raw);

    // Sin máscara (DIMEX, NITE, Extranjero): solo dígitos, nunca guiones.
    if (!current.mask) return digits.slice(0, maxLengthOf(type));

    const capped = digits.slice(0, maxDigitsOf(type));
    const parts = [];
    let offset = 0;

    current.groups.forEach((group) => {
        if (offset >= capped.length) return;
        parts.push(capped.slice(offset, offset + group));
        offset += group;
    });

    if (offset < capped.length) parts.push(capped.slice(offset));

    return parts.join('-');
}

function complete(type, value) {
    const raw = String(value === null || value === undefined ? '' : value);
    if (raw === '' || !isNumericLike(raw)) return false;

    const counts = countsOf(type);
    const length = digitsOf(raw).length;

    if (!counts) return length >= 9 && length <= 12;

    return counts.indexOf(length) !== -1;
}

function transform(type, value) {
    const raw = String(value === null || value === undefined ? '' : value);
    if (raw === '') return '';

    const current = rule(type);
    const counts = current ? countsOf(type) : null;

    if (!current || !isNumericLike(raw)) {
        return raw.slice(0, counts ? LEGACY_MAX : maxLengthOf(type));
    }

    if (!counts) return raw.slice(0, maxLengthOf(type));

    const digits = digitsOf(raw);
    if (counts.indexOf(digits.length) === -1) return '';

    return format(type, digits);
}

function canLookup(type) {
    return LOOKUP_TYPES.indexOf(type) !== -1;
}

/**
 * Deduce el tipo de identificación a partir de la cantidad de dígitos.
 *
 * Sin esto, un formulario recién abierto (tipo en "") NUNCA consulta a
 * Hacienda: el cajero tendría que tocar el desplegable de tipo para ver la
 * propuesta, el nombre oficial y las actividades económicas.
 *
 * 9 dígitos -> 01 Cédula física; 10 -> 02 Cédula jurídica; 11 o 12 -> 03 DIMEX.
 * El tipo 04 NITE comparte 10 dígitos con 02 y 05 Extranjero no admite
 * consulta, así que solo se completan los casos que las reglas fijan de forma
 * unívoca. Si el cajero YA eligió un tipo, ese tipo manda siempre.
 */
function inferType(value) {
    if (!isNumericLike(value)) return null;

    const length = digitsOf(value).length;

    if (length === 9) return '01';
    if (length === 10) return '02';
    if (length === 11 || length === 12) return '03';

    return null;
}

/** Tipo efectivo: el elegido por la persona o, si está vacío, el deducido. */
function resolveType(type, value) {
    if (canLookup(type)) return type;

    return type ? null : inferType(value);
}

async function consult(type, value) {
    // La máscara con guiones es solo visual: a Hacienda siempre viajan dígitos.
    const url = '/clientes/contribuyente?tipo=' + encodeURIComponent(type)
        + '&identificacion=' + encodeURIComponent(digitsOf(value));

    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) throw new Error('lookup_failed');

    return await response.json();
}

const STATUS_TEXTS = {
    loading: 'Consultando…',
    not_found: 'Sin resultados en Hacienda. Puede continuar.',
    error: 'No fue posible consultar Hacienda. Puede continuar.',
};

function statusText(status, name) {
    if (status === 'found') return 'Hacienda: ' + (name || '');
    return STATUS_TEXTS[status] || '';
}

function statusClass(status) {
    if (status === 'found') return 'text-emerald-700';
    if (status === 'error') return 'text-amber-700';
    return 'text-slate-500';
}

window.MvsIdentification = {
    RULES,
    digits: digitsOf,
    isNumericLike,
    maxLength: maxLengthOf,
    placeholder: placeholderOf,
    format,
    complete,
    transform,
    canLookup,
    inferType,
    resolveType,
    consult,
    statusText,
    statusClass,
};

function autofill(input, name) {
    if (input && !input.value.trim()) input.value = name;
}

/**
 * Propuesta oficial de Hacienda (régimen, situación y actividades).
 *
 * Es SOLO propuesta: las actividades se envían al guardar únicamente cuando
 * el usuario las deja marcadas. Aplicarlas es una decisión del usuario, no
 * un efecto automático de la consulta.
 */
function activityLabel(activity) {
    const code = String(activity.code || '');
    const description = String(activity.description || '');
    return description && description !== code ? code + ' — ' + description : code;
}

function renderTaxpayerProposal(data) {
    const box = document.getElementById('taxpayer_proposal');
    if (!box) return;

    box.hidden = false;

    const meta = document.getElementById('taxpayer_meta');
    if (meta) {
        const parts = [];
        if (data.regime) parts.push('Régimen: ' + data.regime);
        if (data.situation) parts.push('Situación: ' + data.situation);
        meta.textContent = parts.length ? parts.join(' · ') : 'Hacienda no informó régimen ni situación.';
    }

    renderActivities(Array.isArray(data.activities) ? data.activities : []);
}

function renderActivities(activities) {
    const box = document.getElementById('taxpayer_activities_box');
    const list = document.getElementById('taxpayer_activities_list');
    if (!box || !list) return;

    // La sección NUNCA se oculta: si no hay actividades se dice explícitamente,
    // para que "no aparece" nunca signifique "no se está mostrando".
    box.hidden = false;

    const vacio = document.getElementById('taxpayer_activities_empty');
    const utiles = activities.filter((activity) => activity && activity.code);

    if (vacio) {
        vacio.textContent = utiles.length
            ? ''
            : 'Hacienda no informó actividades económicas para esta identificación.';
        vacio.hidden = utiles.length > 0;
    }

    list.innerHTML = '';

    utiles.forEach((activity, index) => {

        const item = document.createElement('li');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'h-4 w-4 rounded border-slate-300 text-primary accent-primary';
        checkbox.name = 'taxpayer_activity_candidate';
        checkbox.checked = true;
        checkbox.dataset.code = String(activity.code);
        checkbox.dataset.description = activity.description ? String(activity.description) : '';
        checkbox.id = 'taxpayer_activity_' + index;
        checkbox.addEventListener('change', syncTaxpayerActivityInputs);

        const label = document.createElement('label');
        label.setAttribute('for', checkbox.id);
        label.className = 'ml-2 text-sm text-slate-700';
        label.textContent = activityLabel(activity);

        item.className = 'flex items-start gap-2';
        item.appendChild(checkbox);
        item.appendChild(label);
        list.appendChild(item);
    });

    syncTaxpayerActivityInputs();
}

function syncTaxpayerActivityInputs() {
    const container = document.getElementById('taxpayer_activities_inputs');
    if (!container) return;

    const list = document.getElementById('taxpayer_activities_list');
    container.innerHTML = '';

    if (!list) return;

    list.querySelectorAll('input[type="checkbox"]:checked').forEach((checkbox) => {
        const code = document.createElement('input');
        code.type = 'hidden';
        code.name = 'taxpayer_activities[][code]';
        code.value = checkbox.dataset.code || '';
        container.appendChild(code);

        const description = document.createElement('input');
        description.type = 'hidden';
        description.name = 'taxpayer_activities[][description]';
        description.value = checkbox.dataset.description || '';
        container.appendChild(description);
    });
}

function hideTaxpayerProposal() {
    const box = document.getElementById('taxpayer_proposal');
    if (!box) return;

    box.hidden = true;

    const meta = document.getElementById('taxpayer_meta');
    if (meta) meta.textContent = '';

    const list = document.getElementById('taxpayer_activities_list');
    if (list) list.innerHTML = '';

    const inputs = document.getElementById('taxpayer_activities_inputs');
    if (inputs) inputs.innerHTML = '';
}

function seedTaxpayerProposal() {
    const seed = document.getElementById('taxpayer_proposal_seed');
    if (!seed) return;

    let applied = [];

    try {
        applied = JSON.parse(seed.textContent || '[]');
    } catch (error) {
        applied = [];
    }

    if (!Array.isArray(applied) || applied.length === 0) return;

    const box = document.getElementById('taxpayer_proposal');
    if (box) box.hidden = false;

    const meta = document.getElementById('taxpayer_meta');
    if (meta) meta.textContent = 'Aplicación restaurada del intento anterior.';

    renderActivities(applied);
    syncTaxpayerActivityInputs();
}

function bindTaxpayerProposalActions() {
    const applyAll = document.getElementById('taxpayer_apply_all');
    const clear = document.getElementById('taxpayer_clear');

    if (applyAll) {
        applyAll.addEventListener('click', () => {
            document.querySelectorAll('#taxpayer_activities_list input[type="checkbox"]').forEach((box) => {
                box.checked = true;
            });
            syncTaxpayerActivityInputs();
        });
    }

    if (clear) {
        clear.addEventListener('click', () => {
            document.querySelectorAll('#taxpayer_activities_list input[type="checkbox"]').forEach((box) => {
                box.checked = false;
            });
            syncTaxpayerActivityInputs();
        });
    }
}

function initCustomerForm() {
    const typeInput = document.getElementById('identification_type');
    const numberInput = document.getElementById('identification');

    if (!typeInput || !numberInput || typeInput.dataset.mvsIdentBound === '1') return;

    typeInput.dataset.mvsIdentBound = '1';

    const status = document.getElementById('identification_status');
    const nameInput = document.getElementById('name');
    const taxpayerInput = document.getElementById('taxpayer_name');

    let legacy = numberInput.value !== '' && !isNumericLike(numberInput.value);
    let timer = null;
    let token = 0;

    const render = (state, name) => {
        if (!status) return;
        const text = statusText(state, name);
        status.hidden = text === '';
        status.textContent = text;
        status.className = 'mt-1 text-xs font-medium ' + statusClass(state);
    };

    const applyMeta = () => {
        const type = typeInput.value;
        const limit = Math.max(maxLengthOf(type), numberInput.value.length);
        numberInput.setAttribute('maxlength', String(limit));
        numberInput.setAttribute('placeholder', placeholderOf(type));
        numberInput.setAttribute('inputmode', countsOf(type) ? 'numeric' : 'text');
        numberInput.setAttribute('autocomplete', 'off');
    };

    const run = async (type, value) => {
        const current = ++token;
        render('loading');

        try {
            const data = await consult(type, value);
            if (current !== token) return;

            if (data.status === 'found') {
                render('found', data.name);
                autofill(nameInput, data.name);
                autofill(taxpayerInput, data.name);
                renderTaxpayerProposal(data);
                return;
            }

            hideTaxpayerProposal();
            render(data.status === 'not_found' ? 'not_found' : 'error');
        } catch (error) {
            if (current === token) {
                hideTaxpayerProposal();
                render('error');
            }
        }
    };

    /**
     * Tipo efectivo del formulario. Si la persona no eligió tipo, se deduce de
     * los dígitos y SE REFLEJA en el <select>: así el mismo tipo que se usó
     * para consultar es el que se guarda con el cliente.
     */
    const effectiveType = () => {
        const deduced = resolveType(typeInput.value, numberInput.value);

        if (deduced && !canLookup(typeInput.value)) {
            typeInput.value = deduced;
            applyMeta();
        }

        return typeInput.value;
    };

    const schedule = () => {
        clearTimeout(timer);

        const type = effectiveType();
        const value = numberInput.value;

        if (!canLookup(type) || !complete(type, value)) {
            token += 1;
            hideTaxpayerProposal();
            render('');
            return;
        }

        timer = setTimeout(() => run(type, value), 450);
    };

    numberInput.addEventListener('input', () => {
        if (legacy && numberInput.value === '') legacy = false;

        if (!legacy) {
            const formatted = format(effectiveType(), numberInput.value);
            if (numberInput.value !== formatted) numberInput.value = formatted;
        }

        applyMeta();
        schedule();
    });

    typeInput.addEventListener('change', () => {
        if (legacy) {
            if (numberInput.value === '') legacy = false;
        } else {
            numberInput.value = transform(typeInput.value, numberInput.value);
        }

        applyMeta();
        render('');
        schedule();
    });

    applyMeta();

    bindTaxpayerProposalActions();
    seedTaxpayerProposal();

    if (!legacy && numberInput.value !== '') {
        numberInput.value = format(typeInput.value, numberInput.value);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCustomerForm);
} else {
    initCustomerForm();
}

export { RULES, digitsOf, isNumericLike, maxLengthOf, placeholderOf, format, complete, transform, canLookup, inferType, resolveType, consult };
