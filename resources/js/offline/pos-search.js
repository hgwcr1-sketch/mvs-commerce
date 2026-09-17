import { getSnapshot } from './snapshot.js';
import { getAllOperations } from './pending-operations.js';
import { checkOfflineReady } from './offline-ready.js';

function text(value) {
  return String(value ?? '').trim().toLocaleLowerCase();
}

function numeric(value) {
  const result = Number(value);
  return Number.isFinite(result) ? result : 0;
}

function barcodeValues(product) {
  const values = [];
  if (product.barcode) values.push(String(product.barcode));
  for (const barcode of product.barcodes || []) {
    values.push(typeof barcode === 'string' ? barcode : barcode?.barcode);
  }
  return values.filter(Boolean);
}

function reservedStockByProduct(operations) {
  const reserved = new Map();

  for (const operation of operations || []) {
    if (!['pending', 'syncing'].includes(operation.status)) continue;
    for (const item of operation.payload?.items || []) {
      const productId = Number(item.product_id ?? item.productId);
      const quantity = numeric(item.quantity);
      if (productId > 0 && quantity > 0) {
        reserved.set(productId, numeric(reserved.get(productId)) + quantity);
      }
    }
  }

  return reserved;
}

function productUnit(product, snapshot) {
  const unit = (snapshot.units || []).find((candidate) => Number(candidate.id) === Number(product.unit_id));
  return {
    abbreviation: product.unit_abbreviation ?? unit?.abbreviation ?? null,
    allows_decimals: Boolean(product.allows_decimals ?? unit?.allows_decimals),
  };
}

function productResult(product, snapshot, reserved, quoteMode) {
  const unit = productUnit(product, snapshot);
  const stock = Math.max(0, numeric(product.stock) - numeric(reserved.get(Number(product.id))));
  const barcodes = barcodeValues(product);
  const image = product.image || null;

  return {
    id: Number(product.id),
    name: product.name,
    internal_code: product.internal_code,
    matched_barcode: null,
    sale_price: numeric(product.special_price ?? product.sale_price),
    is_offer: product.special_price !== null && product.special_price !== undefined,
    wholesale_price: product.wholesale_price == null ? null : numeric(product.wholesale_price),
    price_a: product.price_a == null ? null : numeric(product.price_a),
    price_b: product.price_b == null ? null : numeric(product.price_b),
    price_c: product.price_c == null ? null : numeric(product.price_c),
    tax_rate: numeric(product.tax_rate),
    controls_inventory: Boolean(product.track_inventory),
    available_stock: stock,
    unit: unit.abbreviation,
    allows_decimals: unit.allows_decimals,
    can_add_to_cart: quoteMode || !product.track_inventory || stock > 0,
    has_image: Boolean(image),
    image_url: image ? (String(image).startsWith('/') ? String(image) : `/storage/${image}`) : null,
    _offline_snapshot: true,
    _barcodes: barcodes,
  };
}

export function searchSnapshotProducts(snapshot, term, { quoteMode = false, operations = [] } = {}) {
  const query = text(term);
  if (!query) return [];
  const reserved = reservedStockByProduct(operations);

  return (snapshot.products || [])
    .map((product) => {
      const result = productResult(product, snapshot, reserved, quoteMode);
      const matched = result._barcodes.find((barcode) => text(barcode) === query)
        || result._barcodes.find((barcode) => text(barcode).includes(query));
      return { product, result: { ...result, matched_barcode: matched || null }, matched };
    })
    .filter(({ product, matched }) => matched || text(product.name).includes(query) || text(product.internal_code).includes(query))
    .sort((left, right) => {
      const leftExact = left.matched && text(left.matched) === query ? 0 : 1;
      const rightExact = right.matched && text(right.matched) === query ? 0 : 1;
      return leftExact - rightExact || text(left.product.name).localeCompare(text(right.product.name));
    })
    .slice(0, 10)
    .map(({ result }) => {
      delete result._barcodes;
      return result;
    });
}

export function searchSnapshotCustomers(snapshot, term) {
  const query = text(term);
  if (!query) return [];

  return (snapshot.customers || [])
    .filter((customer) => [customer.name, customer.identification, customer.phone, customer.mobile, customer.email, customer.public_code]
      .some((value) => text(value).includes(query)))
    .sort((left, right) => {
      const leftExact = [left.public_code, left.identification].some((value) => text(value) === query) ? 0 : 1;
      const rightExact = [right.public_code, right.identification].some((value) => text(value) === query) ? 0 : 1;
      return leftExact - rightExact || text(left.name).localeCompare(text(right.name));
    })
    .slice(0, 10)
    .map((customer) => ({
      id: Number(customer.id),
      name: customer.name,
      identification: customer.identification,
      phone: customer.phone,
      mobile: customer.mobile,
      email: customer.email,
      public_code: customer.public_code,
      customer_type: customer.customer_type,
      credit_limit: numeric(customer.credit_limit),
      credit_days: Number(customer.credit_days || 0),
      credit_used: numeric(customer.credit_used),
      credit_due_date: customer.credit_due_date || null,
      price_level: customer.price_level || 'normal',
    }));
}

async function readySnapshot(context) {
  const readiness = await checkOfflineReady(context);
  if (!readiness.ready) throw new Error(`Offline no está listo: ${readiness.blockers.join(', ')}`);
  const snapshot = await getSnapshot(context);
  if (!snapshot) throw new Error('Snapshot Offline no disponible.');
  return snapshot;
}

export async function searchProductsOffline(context, term, options = {}) {
  const snapshot = await readySnapshot(context);
  const operations = await getAllOperations(context);
  return searchSnapshotProducts(snapshot, term, { ...options, operations });
}

export async function searchCustomersOffline(context, term) {
  const snapshot = await readySnapshot(context);
  return searchSnapshotCustomers(snapshot, term);
}