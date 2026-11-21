import test from 'node:test';
import assert from 'node:assert/strict';
import { findByBarcode, normalizeScan } from '../../templates/pos/assets/js/barcode.js';
import { product } from './support.js';

const list = [
  product({ variant_id: 'a', barcode: '4800010000011' }),
  product({ variant_id: 'b', barcode: '00123' }),
  product({ variant_id: 'c', barcode: '123' }),
  product({ variant_id: 'd', barcode: null }),
  product({ variant_id: 'e', barcode: '036000291452' }),
  product({ variant_id: 'f', barcode: 'AB-12' }),
];

test('an exact barcode finds its product', () => {
  assert.equal(findByBarcode(list, '4800010000011').variant_id, 'a');
  assert.equal(findByBarcode(list, 'AB-12').variant_id, 'f');
});

test('surrounding spaces and the line break a scanner adds are ignored', () => {
  assert.equal(normalizeScan(' 4800010000011\n'), '4800010000011');
  assert.equal(findByBarcode(list, '4800010000011\r\n').variant_id, 'a');
});

test('leading zeros count: 00123 and 123 are different codes', () => {
  assert.equal(findByBarcode(list, '00123').variant_id, 'b');
  assert.equal(findByBarcode(list, '123').variant_id, 'c');
  assert.equal(findByBarcode(list, '0123'), null);
  assert.equal(findByBarcode(list, '000123'), null);
});

test('a barcode is never turned into a number', () => {
  assert.equal(findByBarcode(list, '4.800010000011e12'), null);
  assert.equal(findByBarcode([product({ variant_id: 'z', barcode: '0012' })], '12'), null);
});

test('a UPC-A and its 13-digit EAN form match each other, in both directions', () => {
  assert.equal(findByBarcode(list, '0036000291452').variant_id, 'e');
  const ean = [product({ variant_id: 'g', barcode: '0036000291452' })];
  assert.equal(findByBarcode(ean, '036000291452').variant_id, 'g');
});

test('the UPC and EAN equivalence is only between 12 and 13 digits with one leading zero', () => {
  assert.equal(findByBarcode(list, '00036000291452'), null);
  assert.equal(findByBarcode(list, '136000291452'), null);
  assert.equal(findByBarcode(list, '1036000291452'), null);
});

test('an exact match wins over the equivalence', () => {
  const both = [product({ variant_id: 'x', barcode: '0036000291452' }), product({ variant_id: 'y', barcode: '036000291452' })];
  assert.equal(findByBarcode(both, '036000291452').variant_id, 'y');
});

test('a miss is null, and so are an empty scan and a product without a barcode', () => {
  assert.equal(findByBarcode(list, '9999999999999'), null);
  assert.equal(findByBarcode(list, ''), null);
  assert.equal(findByBarcode(list, '   '), null);
  assert.equal(findByBarcode(list, null), null);
});
