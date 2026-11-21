import test from 'node:test';
import assert from 'node:assert/strict';
import { priceSale, mulDiv, extractVat, applyDiscount, bestPrice } from '../../templates/pos/assets/js/pricing.js';
import { parseQuantity, toApiMoney, toApiQuantity } from '../../templates/pos/assets/js/money.js';
import { loadPricingFixture, snapshotProduct } from './support.js';

const fixture = loadPricingFixture();

test('the pricing fixture has at least 25 cases', () => {
  assert.ok(fixture.cases.length >= 25, `only ${fixture.cases.length} cases`);
});

for (const c of fixture.cases) {
  test(`fixture: ${c.name}`, () => {
    const products = Object.fromEntries(Object.entries(c.products).map(([key, p]) => [key, snapshotProduct(key, p)]));

    // The ticket lists a product once, so entries of the same product are added together first.
    const merged = new Map();
    for (const line of c.lines) merged.set(line.product, (merged.get(line.product) ?? 0) + parseQuantity(line.quantity));
    const entries = [...merged].map(([key, quantityMilli]) => ({ product: products[key], quantityMilli }));

    const result = priceSale(entries, c.discount);
    const e = c.expected;

    assert.deepEqual(
      result.lines.map((line) => ({ product: line.variantId, quantity: toApiQuantity(line.quantityMilli), unit_price: toApiMoney(line.unitPrice), tier_label: line.tierLabel, line_total: toApiMoney(line.lineTotal) })),
      e.lines,
    );
    assert.equal(toApiMoney(result.subtotal), e.subtotal);
    assert.equal(toApiMoney(result.discountTotal), e.discount_total);
    assert.equal(toApiMoney(result.total), e.total);
    assert.equal(toApiMoney(result.vatAmount), e.vat_amount);
    assert.equal(result.netOfVat, result.total - result.vatAmount);
  });
}

test('rounding is half away from zero, also below zero', () => {
  assert.equal(mulDiv(5, 1, 2), 3);
  assert.equal(mulDiv(-5, 1, 2), -3);
  assert.equal(mulDiv(7, 1, 3), 2);
  assert.equal(mulDiv(-7, 1, 3), -2);
  assert.equal(mulDiv(14, 100, 112), 13); // 12.5 centavos of VAT-free price rounds up
});

test('an amount that cannot be worked out exactly is refused, like the server', () => {
  assert.throws(() => mulDiv(1e15, 1e15, 1), RangeError);
  assert.throws(() => mulDiv(1, 1, 0), RangeError);
});

test('VAT is 12% extracted from a VAT-inclusive total', () => {
  assert.deepEqual(extractVat(11200), { net: 10000, vat: 1200 });
  assert.deepEqual(extractVat(9999), { net: 8928, vat: 1071 });
});

test('a VAT-exempt rule removes the VAT first and then takes the percent of the net', () => {
  assert.deepEqual(applyDiscount(11200, { percent: '20.00', vat_exempt: true }), { discountTotal: 3200, total: 8000, vatAmount: 0 });
});

test('the order matters at the centavo: VAT first, then the percent of the VAT-free amount', () => {
  // The two orders agree on round amounts and differ by a centavo on others (0.05 gives 0.03 one way and 0.04 the other).
  const rule = { percent: '20.00', vat_exempt: true };
  assert.equal(applyDiscount(5, rule).total, 3);
  assert.equal(applyDiscount(5010, rule).total, 3578);
  const percentFirst = (subtotal) => extractVat(subtotal - mulDiv(subtotal, 2000, 10000)).net;
  assert.equal(percentFirst(5), 4);
  assert.equal(percentFirst(5010), 3579);
});

test('a plain rule keeps VAT inside the discounted total', () => {
  assert.deepEqual(applyDiscount(11200, { percent: '10.00', vat_exempt: false }), { discountTotal: 1120, total: 10080, vatAmount: 1080 });
});

test('the lowest matching quantity tier wins and a tier above retail is ignored', () => {
  const p = snapshotProduct('a', { price: '112.00', decimals: false, step: '1', min: '1', tiers: [
    { label: '3 or more', min_qty: '3', price: '100.00' }, { label: '10 or more', min_qty: '10', price: '90.00' }, { label: 'dear', min_qty: '1', price: '150.00' },
  ] });
  assert.deepEqual(bestPrice(p, 2000), { price: 11200, label: null });
  assert.deepEqual(bestPrice(p, 3000), { price: 10000, label: '3 or more' });
  assert.deepEqual(bestPrice(p, 12000), { price: 9000, label: '10 or more' });
});
