const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');

const ROOT = 'C:\\Users\\USER000\\MVS Commerce\\mvs-commerce-paralelo-2';
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8');

describe('Offline POS search contracts', async () => {
  const { searchSnapshotProducts, searchSnapshotCustomers } = await import(
    pathToFileURL(path.join(ROOT, 'resources/js/offline/pos-search.js')).href
  );

  const snapshot = {
    units: [{ id: 7, abbreviation: 'kg', allows_decimals: true }],
    products: [{
      id: 10,
      unit_id: 7,
      name: 'Cafe Molido',
      internal_code: 'CAF-10',
      barcode: '7500000000100',
      barcodes: [{ barcode: '7500000000101' }],
      sale_price: '1200.00',
      special_price: '999.00',
      wholesale_price: '900.00',
      price_a: '1100.00',
      price_b: '1050.00',
      price_c: '1000.00',
      tax_rate: '13.00',
      track_inventory: true,
      stock: '5.0000',
    }],
    customers: [{
      id: 20,
      name: 'Cliente Offline',
      identification: 'ID-20',
      phone: '88880000',
      mobile: null,
      email: 'cliente@example.test',
      public_code: 'ABC12345',
      customer_type: 'individual',
      credit_limit: '10000.00',
      credit_days: 15,
      credit_used: '1000.00',
      price_level: 'price_a',
    }],
  };

  it('searches products by name, SKU, primary and additional barcode', () => {
    for (const term of ['cafe', 'CAF-10', '7500000000100', '7500000000101']) {
      assert.equal(searchSnapshotProducts(snapshot, term).length, 1, term);
    }
  });

  it('preserves the current product contract and effective pending stock', () => {
    const [product] = searchSnapshotProducts(snapshot, 'cafe', {
      operations: [{
        status: 'pending',
        payload: { items: [{ product_id: 10, quantity: '2' }] },
      }],
    });

    assert.deepEqual(product, {
      id: 10,
      name: 'Cafe Molido',
      internal_code: 'CAF-10',
      matched_barcode: null,
      sale_price: 999,
      is_offer: true,
      wholesale_price: 900,
      price_a: 1100,
      price_b: 1050,
      price_c: 1000,
      tax_rate: 13,
      controls_inventory: true,
      available_stock: 3,
      unit: 'kg',
      allows_decimals: true,
      can_add_to_cart: true,
      has_image: false,
      image_url: null,
      _offline_snapshot: true,
    });
  });

  it('does not allow inventory products to pass silently below zero', () => {
    const [product] = searchSnapshotProducts(snapshot, 'cafe', {
      operations: [{ status: 'syncing', payload: { items: [{ product_id: 10, quantity: 6 }] } }],
    });
    assert.equal(product.available_stock, 0);
    assert.equal(product.can_add_to_cart, false);
  });

  it('searches customers by name, identification, phone and email', () => {
    for (const term of ['cliente offline', 'ID-20', '88880000', 'cliente@example.test']) {
      assert.equal(searchSnapshotCustomers(snapshot, term).length, 1, term);
    }
  });

  it('preserves customer_id and POS customer fields', () => {
    const [customer] = searchSnapshotCustomers(snapshot, 'ABC12345');
    assert.equal(customer.id, 20);
    assert.equal(customer.credit_used, 1000);
    assert.equal(customer.price_level, 'price_a');
  });

  it('keeps HTTP responses out of the Offline fallback', () => {
    const source = read('resources/views/pos/index.blade.php');
    assert.match(source, /error\.status = response\.status/);
    assert.match(source, /!error\.status && window\.MvsOffline\?\.searchProductsOffline/);
    assert.match(source, /!error\.status && window\.MvsOffline\?\.searchCustomersOffline/);
  });
});