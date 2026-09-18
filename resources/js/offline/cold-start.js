import { getAll, STORES } from './db.js';
import { getSnapshot, validateSnapshot } from './snapshot.js';
import { searchSnapshotCustomers, searchSnapshotProducts } from './pos-search.js';
import { verifyAuthSignature } from './auth-verification.js';

const PUBLIC_KEY_STORAGE_KEY = 'mvs_offline_public_key';
const MAX_OFFLINE_WINDOW_MS = 48 * 60 * 60 * 1000;

function element(id) {
    return document.getElementById(id);
}

function localPublicKey() {
    let stored;
    try {
        stored = JSON.parse(localStorage.getItem(PUBLIC_KEY_STORAGE_KEY) || 'null');
    } catch (_) {
        return null;
    }

    const key = typeof stored?.key === 'string' ? stored.key.trim() : '';
    if (!key || (!key.includes('BEGIN PUBLIC KEY') && !key.includes('BEGIN RSA PUBLIC KEY'))) return null;
    return key;
}

function contextFromRecord(record) {
    if (!record?.company_id || !record?.branch_id || !record?.terminal_uuid || !record?.user_id || !record.token) return null;
    return {
        company_id: Number(record.company_id),
        branch_id: Number(record.branch_id),
        terminal_uuid: String(record.terminal_uuid),
        user_id: Number(record.user_id),
    };
}

function currentAuthWindow(record) {
    const issuedAt = Date.parse(record.issued_at);
    const validUntil = Date.parse(record.valid_until);
    const now = Date.now();
    if (!Number.isFinite(issuedAt) || !Number.isFinite(validUntil)) return false;
    return issuedAt <= now && validUntil > now && now - issuedAt <= MAX_OFFLINE_WINDOW_MS;
}

async function validCandidates(records, publicKey) {
    const candidates = [];
    for (const record of records) {
        const context = contextFromRecord(record);
        if (!context || !currentAuthWindow(record)) continue;
        const verified = await verifyAuthSignature(record.token, publicKey, context);
        if (!verified.valid) continue;
        // Legacy v1 tokens remain usable by 4B.1B, but cannot identify a user
        // for Cold Start. Only signed claims may authorize this context.
        if (!Number.isSafeInteger(verified.auth.user_id) || verified.auth.user_id <= 0
            || verified.auth.user_id !== context.user_id) continue;
        if (!currentAuthWindow(verified.auth)) continue;
        if (Date.parse(verified.auth.valid_until) - Date.parse(verified.auth.issued_at) > MAX_OFFLINE_WINDOW_MS) continue;
        candidates.push({ context, authorization: verified.auth, auth: verified.auth });
    }
    return candidates;
}

async function recoverContext() {
    const publicKey = localPublicKey();
    if (!publicKey) throw new Error('Clave pública Offline ausente o inválida.');

    const records = (await getAll(STORES.metadata)).filter((record) => record?.token);
    const candidates = await validCandidates(records, publicKey);
    if (candidates.length === 0) throw new Error('No existe una identidad Offline autorizada y vigente en esta terminal. Reconecte y abra POS para renovar la autorización.');
    if (candidates.length > 1) throw new Error('Hay más de una identidad Offline válida. Conecte la terminal y seleccione un único contexto.');

    const candidate = candidates[0];
    const snapshot = await getSnapshot(candidate.context);
    if (!snapshot) throw new Error('Snapshot Offline ausente para la identidad autorizada.');

    const snapshotCheck = validateSnapshot(snapshot, candidate.context);
    if (!snapshotCheck.valid) throw new Error(`Snapshot Offline rechazado: ${snapshotCheck.error}`);
    if (Number(snapshot.user?.id) !== candidate.context.user_id) throw new Error('El usuario del snapshot no coincide con la autorización local.');

    return { ...candidate, snapshot };
}

function setStatus(title, detail, state = 'ready') {
    const status = element('mvs-offline-status');
    status.dataset.state = state;
    element('mvs-offline-status-title').textContent = title;
    element('mvs-offline-status-detail').textContent = detail;
}

function money(value) {
    return Number(value || 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function mountPos(recovered) {
    const { context, authorization, snapshot } = recovered;
    const cart = [];
    const selectedCustomer = { value: null };
    const productQuery = element('mvs-offline-product-query');
    const customerQuery = element('mvs-offline-customer-query');
    const productResults = element('mvs-offline-product-results');
    const customerResults = element('mvs-offline-customer-results');
    const cartElement = element('mvs-offline-cart');
    const totalElement = element('mvs-offline-cart-total');

    element('mvs-offline-context').textContent = `${snapshot.company.trade_name} / ${snapshot.branch.name} / ${snapshot.user.name}`;
    element('mvs-offline-auth').textContent = `Terminal autorizada. Vigencia hasta ${new Date(authorization.valid_until).toLocaleString('es-CR')}.`;
    element('mvs-offline-pos').hidden = false;

    const renderCart = () => {
        cartElement.replaceChildren();
        if (!cart.length) {
            const empty = document.createElement('p');
            empty.className = 'shell-muted';
            empty.textContent = 'Seleccione productos para construir el carrito.';
            cartElement.append(empty);
        }
        let total = 0;
        cart.forEach((item, index) => {
            total += item.sale_price * item.quantity * (1 + item.tax_rate / 100);
            const row = document.createElement('div');
            row.className = 'shell-cart-row';
            row.innerHTML = `<span>${item.name}<small class="shell-muted"> ${money(item.sale_price)} c/u</small></span><input type="number" min="1" step="1" value="${item.quantity}" aria-label="Cantidad de ${item.name}"><button type="button">Quitar</button>`;
            row.querySelector('input').addEventListener('change', (event) => {
                item.quantity = Math.max(1, Number(event.target.value) || 1);
                renderCart();
            });
            row.querySelector('button').addEventListener('click', () => {
                cart.splice(index, 1);
                renderCart();
            });
            cartElement.append(row);
        });
        totalElement.textContent = cart.length ? `Total estimado: ${money(total)}` : '';
    };

    const renderProducts = () => {
        productResults.replaceChildren();
        const products = searchSnapshotProducts(snapshot, productQuery.value, { operations: [] });
        products.forEach((product) => {
            const row = document.createElement('div');
            row.className = 'shell-item';
            row.innerHTML = `<span><strong>${product.name}</strong><small class="shell-muted"> ${product.internal_code || ''} · ${money(product.sale_price)} · stock ${product.available_stock}</small></span><button type="button">Agregar</button>`;
            row.querySelector('button').disabled = !product.can_add_to_cart;
            row.querySelector('button').addEventListener('click', () => {
                const existing = cart.find((item) => item.id === product.id);
                if (existing) existing.quantity += 1;
                else cart.push({ ...product, quantity: 1 });
                renderCart();
            });
            productResults.append(row);
        });
    };

    const renderCustomers = () => {
        customerResults.replaceChildren();
        searchSnapshotCustomers(snapshot, customerQuery.value).forEach((customer) => {
            const row = document.createElement('div');
            row.className = 'shell-item';
            row.innerHTML = `<span><strong>${customer.name}</strong><small class="shell-muted"> ${customer.public_code || customer.identification || ''}</small></span><button type="button">Seleccionar</button>`;
            row.querySelector('button').addEventListener('click', () => {
                selectedCustomer.value = customer;
                customerQuery.value = customer.name;
                customerResults.replaceChildren();
            });
            customerResults.append(row);
        });
    };

    element('mvs-offline-product-search').addEventListener('click', renderProducts);
    element('mvs-offline-customer-search').addEventListener('click', renderCustomers);
    productQuery.addEventListener('keydown', (event) => { if (event.key === 'Enter') renderProducts(); });
    customerQuery.addEventListener('keydown', (event) => { if (event.key === 'Enter') renderCustomers(); });
    renderCart();
    setStatus('POS Offline disponible', 'Contexto, RSA y snapshot validados localmente. El cobro permanece bloqueado sin caja recuperable.', 'ready');
}

export async function startColdStart() {
    if (!document.getElementById('mvs-offline-shell')) {
        return { ready: false, reason: 'Cold Start shell not present.' };
    }

    try {
        const recovered = await recoverContext();
        mountPos(recovered);
        return { ready: true, context: recovered.context };
    } catch (error) {
        setStatus('POS Offline bloqueado', error.message, 'blocked');
        return { ready: false, reason: error.message };
    }
}

if (typeof window !== 'undefined') {
    const start = () => startColdStart();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
}
