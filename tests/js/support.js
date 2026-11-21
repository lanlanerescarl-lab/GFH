// Shared helpers for the POS JavaScript tests.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

export const modulePath = (name) => join(here, '..', '..', 'templates', 'pos', 'assets', 'js', name);

export function loadPricingFixture() {
  return JSON.parse(readFileSync(join(here, '..', 'fixtures', 'pos-pricing.json'), 'utf8'));
}

/** A snapshot product as the server's snapshot() sends it, built from a fixture product. */
export function snapshotProduct(key, p) {
  return {
    variant_id: key,
    sku: `SKU-${key}`,
    barcode: null,
    name: `Product ${key}`,
    price: p.price,
    allow_decimal_qty: p.decimals,
    qty_step: p.step,
    min_qty: p.min,
    on_hand: '100.000',
    media_id: null,
    tiers: p.tiers.map((t) => ({ label: t.label, min_qty: t.min_qty, price: t.price })),
  };
}

/** A small product for rule tests. */
export function product(overrides = {}) {
  return {
    variant_id: 'v1', sku: 'COF-1', barcode: '4800010000011', name: 'Coffee 250 g', unit: 'pc', price: '112.00',
    allow_decimal_qty: false, qty_step: '1.000', min_qty: '1.000', on_hand: '10.000', media_id: null, tiers: [], ...overrides,
  };
}
