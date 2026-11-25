// Node tests for the checkout page's pure rules and the display formatting. Run: node --test tests/js
import assert from 'node:assert/strict';
import test from 'node:test';

import {
  addressPrefill, checkoutBody, fulfillmentBody, itemsFrom, pricingBody, pricingFulfillment, shortStockNotes, splitErrors,
} from '../../templates/shop/assets/js/checkout-model.js';
import { formatDate, formatPeso, formatQuantity, isPositiveAmount, paymentMethodLabel } from '../../templates/shop/assets/js/format.js';

const A = '11111111-1111-4111-8111-111111111111';
const lines = [{ variant_id: A, quantity: '2', price: '1.00', name: 'x' }];

test('only variant ids and quantities are sent as items', () => {
  assert.deepEqual(itemsFrom(lines), [{ variant_id: A, quantity: '2' }]);
});

test('pickup and delivery bodies for checkout', () => {
  assert.deepEqual(fulfillmentBody({ method: 'pickup', branch_id: ' b1 ' }), { method: 'pickup', branch_id: 'b1' });
  const delivery = fulfillmentBody({ method: 'delivery', branch_id: 'ignored', address: { line1: ' 12 Rizal St ', city: 'Bacolod', province: 'Negros Occidental', postal_code: '6100', extra: 'x' } });
  assert.equal(delivery.method, 'delivery');
  assert.equal(delivery.line1, '12 Rizal St');
  assert.equal(delivery.recipient, '');
  assert.equal(delivery.branch_id, undefined);
  assert.equal(delivery.extra, undefined);
  assert.deepEqual(checkoutBody(lines, { method: 'pickup', branch_id: 'b1' }).checkout, {});
});

test('pricing sends the same delivery choice in the shape the server hooks see at checkout', () => {
  const values = { method: 'delivery', address: { line1: '12 Rizal St', city: 'Bacolod', province: 'Negros Occidental' } };
  const priced = pricingFulfillment(values);
  assert.equal(priced.method, 'delivery');
  assert.equal(priced.address.city, 'Bacolod');
  assert.equal(priced.line1, undefined);
  assert.deepEqual(pricingFulfillment({ method: 'pickup', branch_id: 'b1' }), { method: 'pickup', branch_id: 'b1' });
  assert.deepEqual(pricingBody(lines, { method: 'pickup', branch_id: 'b1' }).items, [{ variant_id: A, quantity: '2' }]);
});

test('the saved default address pre-fills the delivery form; a failed or odd answer leaves it empty', () => {
  const filled = addressPrefill({ items: [
    { line1: 'Other', city: 'X', province: 'Y', is_default: false },
    { line1: '12 Rizal St', barangay: 'Brgy 5', city: 'Bacolod', province: 'Negros Occidental', postal_code: '6100', is_default: true },
  ] });
  assert.equal(filled.line1, '12 Rizal St');
  assert.equal(filled.postal_code, '6100');
  assert.equal(filled.recipient, '');
  for (const odd of [undefined, null, {}, { items: 'x' }, { items: [] }, { items: [{ line1: 'x', is_default: false }] }]) {
    assert.ok(Object.values(addressPrefill(odd)).every((value) => value === ''), JSON.stringify(odd));
  }
});

test('server field errors split into form fields and general messages', () => {
  const split = splitErrors({
    'fulfillment.line1': 'This field is required.',
    'fulfillment.branch_id': 'Choose a branch to pick up from.',
    items: 'Not enough stock for: A (only 1 available).',
    [`items.${A}`]: 'This product is no longer available.',
    'checkout.note': 'Too long.',
    bad: '',
  });
  // Batch 8.3: `checkout.{id}` is the error of an extension field and is kept under that key for the field's box.
  assert.deepEqual(split.fields, { line1: 'This field is required.', branch_id: 'Choose a branch to pick up from.', 'checkout.note': 'Too long.' });
  assert.equal(split.general.length, 2);
  assert.deepEqual(splitErrors(undefined), { fields: {}, general: [] });
});

test('a stock shortage is put in plain words with product names, or left alone', () => {
  const priced = [
    { sku: 'CH-1', product_name: 'Walnut Chair', variant_name: 'Oak', quantity: '5.000' },
    { sku: 'TB-2', product_name: 'Table', variant_name: 'Table', quantity: '1.000' },
    { sku: 'XX-9', product_name: 'Other', variant_name: 'Default' },
  ];
  const notes = shortStockNotes('Not enough stock for: CH-1 (only 3 available), TB-2 (only 0 available).', priced);
  assert.deepEqual(notes, [{ name: 'Walnut Chair (Oak)', available: '3' }, { name: 'Table', available: '0' }]);
  assert.equal(shortStockNotes('Some products in the cart are no longer available.', priced), null);
  assert.equal(shortStockNotes('Not enough stock for: ZZ-0 (only 1 available).', priced), null, 'an unknown SKU falls back to the server sentence');
  assert.equal(shortStockNotes(undefined, priced), null);
  assert.equal(shortStockNotes('Not enough stock for: CH-1 (only 3 available).', undefined), null);
  assert.deepEqual(shortStockNotes('Not enough stock for: A.B (only 2.5 available).', [{ sku: 'A.B', product_name: 'Dotted' }]), [{ name: 'Dotted', available: '2.5' }]);
});

test('money is grouped and given the peso sign without floating point', () => {
  assert.equal(formatPeso('224.00'), '₱224.00');
  assert.equal(formatPeso('1234567.5'), '₱1,234,567.50');
  assert.equal(formatPeso('0'), '₱0.00');
  assert.equal(formatPeso('-12'), '-₱12.00');
  assert.equal(formatPeso('-0.00'), '₱0.00');
  assert.equal(formatPeso('0.1'), '₱0.10');
  assert.equal(formatPeso('99999999999999.99'), '₱99,999,999,999,999.99');
  for (const bad of ['', 'abc', null, undefined, '1e5', '1,000']) {
    assert.equal(formatPeso(bad), '₱0.00', String(bad));
  }
});

test('dates are DD Mon YYYY and quantities are trimmed', () => {
  assert.equal(formatDate('2026-12-05'), '05 Dec 2026');
  assert.equal(formatDate('2026-01-31T16:30:00+08:00'), '31 Jan 2026');
  assert.equal(formatDate('nope'), '');
  assert.equal(formatDate(null), '');
  assert.equal(formatDate('2026-13-01'), '');
  assert.equal(formatQuantity('2.000'), '2');
  assert.equal(formatQuantity('0.500'), '0.5');
  assert.equal(isPositiveAmount('0.00'), false);
  assert.equal(isPositiveAmount('0.01'), true);
  assert.equal(isPositiveAmount(undefined), false);
  assert.equal(paymentMethodLabel('gcash'), 'GCash');
  assert.equal(paymentMethodLabel('cod'), 'Cash on delivery');
  assert.equal(paymentMethodLabel('weird_one'), 'weird_one');
});
