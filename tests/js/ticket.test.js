import test from 'node:test';
import assert from 'node:assert/strict';
import {
  emptyTicket, addProduct, adjustQuantity, setQuantity, removeLine, quantityOf, validateQuantity, firstQuantity,
  pricingEntries, discountNeedsId, checkIdLast4,
} from '../../templates/pos/assets/js/ticket.js';
import { priceSale } from '../../templates/pos/assets/js/pricing.js';
import { indexProducts, searchProducts, stockStatus, oversellNote } from '../../templates/pos/assets/js/catalog.js';
import { product } from './support.js';

const whole = product();
const kilo = product({ variant_id: 'k', sku: 'RICE', name: 'Rice by the kilo', allow_decimal_qty: true, qty_step: '0.250', min_qty: '0.500', price: '60.00', barcode: null });

test('adding the same product twice makes one line with a bigger quantity', () => {
  let t = addProduct(emptyTicket(), whole).ticket;
  t = addProduct(t, whole).ticket;
  assert.equal(t.lines.length, 1);
  assert.equal(quantityOf(t, 'v1'), 2000);
});

test('a product starts at its minimum quantity and grows by its step', () => {
  assert.equal(firstQuantity(kilo), 500);
  let t = addProduct(emptyTicket(), kilo).ticket;
  assert.equal(quantityOf(t, 'k'), 500);
  t = addProduct(t, kilo).ticket;
  assert.equal(quantityOf(t, 'k'), 750);
});

test('a whole-number product starts at its whole-number minimum', () => {
  const sixPack = product({ variant_id: 's', min_qty: '6.000' });
  assert.equal(quantityOf(addProduct(emptyTicket(), sixPack).ticket, 's'), 6000);
});

test('quantity rules give the same messages as the server', () => {
  assert.equal(validateQuantity(whole, 1500), 'Use a whole number.');
  assert.equal(validateQuantity(whole, 0), 'The quantity must be greater than zero.');
  assert.equal(validateQuantity(whole, 2e9), 'That quantity is too large.');
  assert.equal(validateQuantity(kilo, 600), 'Use a multiple of 0.25.');
  assert.equal(validateQuantity(kilo, 250), 'The minimum is 0.5.');
  assert.equal(validateQuantity(kilo, 1250), null);
  assert.equal(validateQuantity(whole, 1.5), 'Enter a valid quantity (up to 3 decimals).');
});

test('numeric entry changes a line, and a refused quantity leaves the ticket as it was', () => {
  const t = addProduct(emptyTicket(), kilo).ticket;
  const ok = setQuantity(t, kilo, 2500);
  assert.equal(ok.error, null);
  assert.equal(quantityOf(ok.ticket, 'k'), 2500);

  const bad = setQuantity(t, kilo, 333);
  assert.equal(bad.error, 'Use a multiple of 0.25.');
  assert.equal(bad.ticket, t);
});

test('minus stops at the minimum and never removes the line; Remove does', () => {
  let t = addProduct(emptyTicket(), kilo).ticket;
  const result = adjustQuantity(t, kilo, -1);
  assert.equal(result.error, 'The minimum is 0.5.');
  assert.equal(quantityOf(result.ticket, 'k'), 500);

  t = removeLine(t, 'k');
  assert.equal(t.lines.length, 0);
});

test('plus and minus move by one step', () => {
  let t = setQuantity(addProduct(emptyTicket(), kilo).ticket, kilo, 1000).ticket;
  t = adjustQuantity(t, kilo, 1).ticket;
  assert.equal(quantityOf(t, 'k'), 1250);
  t = adjustQuantity(t, kilo, -1).ticket;
  assert.equal(quantityOf(t, 'k'), 1000);
});

test('the ticket is never changed in place', () => {
  const before = addProduct(emptyTicket(), whole).ticket;
  const frozen = JSON.stringify(before);
  addProduct(before, whole);
  adjustQuantity(before, whole, 1);
  removeLine(before, 'v1');
  assert.equal(JSON.stringify(before), frozen);
});

test('a quantity tier is reached by adding the same product again and again', () => {
  const tiered = product({ tiers: [{ label: '3 or more', min_qty: '3.000', price: '100.00' }] });
  const productsByVariant = indexProducts([tiered]);
  let t = emptyTicket();
  for (let i = 0; i < 2; i += 1) t = addProduct(t, tiered).ticket;
  assert.equal(priceSale(pricingEntries(t, productsByVariant)).lines[0].unitPrice, 11200);
  t = addProduct(t, tiered).ticket;
  const priced = priceSale(pricingEntries(t, productsByVariant));
  assert.equal(priced.lines[0].unitPrice, 10000);
  assert.equal(priced.lines[0].tierLabel, '3 or more');
  assert.equal(priced.total, 30000);
});

test('a line whose product left the snapshot is not priced', () => {
  const t = addProduct(emptyTicket(), whole).ticket;
  assert.deepEqual(pricingEntries(t, new Map()), []);
});

test('only VAT-exempt rules ask for the last four characters of an ID', () => {
  assert.equal(discountNeedsId({ key: 'senior', vat_exempt: true }), true);
  assert.equal(discountNeedsId({ key: 'promo', vat_exempt: false }), false);
  assert.equal(discountNeedsId(null), false);
});

test('the ID reference is the last four characters only, never the whole number', () => {
  assert.deepEqual(checkIdLast4(' ab12 '), { ok: true, value: 'AB12', message: null });
  assert.equal(checkIdLast4('').ok, false);
  assert.equal(checkIdLast4('12345').ok, false);
  assert.match(checkIdLast4('1234567890').message, /never the whole number/);
  assert.equal(checkIdLast4('12 4').ok, false);
  assert.equal(checkIdLast4('12-4').ok, false);
});

test('search matches name, SKU and barcode, every word, ignoring case', () => {
  const list = [whole, kilo];
  assert.deepEqual(searchProducts(list, 'coffee').map((p) => p.variant_id), ['v1']);
  assert.deepEqual(searchProducts(list, 'RICE kilo').map((p) => p.variant_id), ['k']);
  assert.deepEqual(searchProducts(list, '4800010000011').map((p) => p.variant_id), ['v1']);
  assert.deepEqual(searchProducts(list, 'coffee rice'), []);
  assert.equal(searchProducts(list, '  ').length, 2);
});

test('stock badges come from on_hand and never block anything', () => {
  assert.equal(stockStatus(product({ on_hand: '10.000' })), 'ok');
  assert.equal(stockStatus(product({ on_hand: '5.000' })), 'low');
  assert.equal(stockStatus(product({ on_hand: '0.000' })), 'out');
  assert.equal(stockStatus(product({ on_hand: '-2.000' })), 'out');
  assert.equal(oversellNote(product({ on_hand: '3.000' }), 3000), null);
  assert.match(oversellNote(product({ on_hand: '3.000' }), 4000), /Only 3 in stock/);
  assert.match(oversellNote(product({ on_hand: '0.000' }), 1000), /None in stock/);
  // An oversell is allowed on the ticket itself.
  let t = emptyTicket();
  for (let i = 0; i < 20; i += 1) t = addProduct(t, product({ on_hand: '1.000' })).ticket;
  assert.equal(quantityOf(t, 'v1'), 20000);
});
