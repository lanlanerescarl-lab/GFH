// Node tests for the browser cart (templates/shop/assets/js/cart.js): the rules and the stored format, no DOM.
// Run: node --test tests/js
import assert from 'node:assert/strict';
import test from 'node:test';

import {
  MAX_LINES, STORAGE_VERSION, addToCart, checkQuantity, fromMilli, loadCart, parseCart, removeLine, saveCart,
  serializeCart, setQuantity, storageKey, toMilli,
} from '../../templates/shop/assets/js/cart.js';

const A = '11111111-1111-4111-8111-111111111111';
const B = '22222222-2222-4222-8222-222222222222';
const WHOLE = { decimal: false, step: '1', min: '1' };
const KILOS = { decimal: true, step: '0.5', min: '0.5' };

/** A stand-in for localStorage. */
function fakeStorage(initial = {}) {
  const values = new Map(Object.entries(initial));
  return {
    values,
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };
}

test('the storage key includes the organization slug', () => {
  assert.notEqual(storageKey('acme'), storageKey('other'));
  assert.ok(storageKey('acme').includes('acme'));
});

test('quantities are exact: no floating-point drift', () => {
  assert.equal(toMilli('0.1'), 100);
  assert.equal(toMilli('1.005'), 1005);
  assert.equal(toMilli('2.000'), 2000);
  assert.equal(toMilli(3), 3000);
  assert.equal(fromMilli(1500), '1.5');
  assert.equal(fromMilli(2000), '2');
  for (const bad of ['', 'abc', '-1', '1.2345', '1e3', ' ', '1,5', null, undefined, {}]) {
    assert.equal(toMilli(bad), null, String(bad));
  }
});

test('adding puts a new line in the cart', () => {
  const result = addToCart([], A, '2', WHOLE);
  assert.equal(result.ok, true);
  assert.deepEqual(result.lines, [{ variant_id: A, quantity: '2' }]);
});

test('adding the same variant adds to the existing line instead of making a second one', () => {
  const first = addToCart([], A, '2', WHOLE);
  const second = addToCart(first.lines, A.toUpperCase(), '3', WHOLE);
  assert.equal(second.ok, true);
  assert.deepEqual(second.lines, [{ variant_id: A, quantity: '5' }]);
  const other = addToCart(second.lines, B, '1', WHOLE);
  assert.equal(other.lines.length, 2);
});

test('the same variant with decimals adds exactly', () => {
  const result = addToCart(addToCart([], A, '0.5', KILOS).lines, A, '1.5', KILOS);
  assert.deepEqual(result.lines, [{ variant_id: A, quantity: '2' }]);
});

test('a whole-number variant refuses decimals; a decimal variant must follow its step and minimum', () => {
  assert.equal(addToCart([], A, '1.5', WHOLE).error, 'whole_number');
  assert.equal(addToCart([], A, '0.7', KILOS).error, 'step');
  assert.equal(addToCart([], A, '0.25', KILOS).error, 'step');
  assert.equal(addToCart([], A, '0', KILOS).error, 'invalid_quantity');
  assert.equal(addToCart([], A, '1', { decimal: false, step: '1', min: '3' }).error, 'below_minimum');
  assert.equal(addToCart([], A, '3', { decimal: false, step: '1', min: '3' }).ok, true);
  assert.equal(addToCart([], A, '1.5', KILOS).ok, true);
  assert.equal(addToCart([], A, 'abc', WHOLE).error, 'invalid_quantity');
  assert.equal(addToCart([], A, '-2', WHOLE).error, 'invalid_quantity');
});

test('the rules come from the data given, not from the module', () => {
  assert.equal(checkQuantity('2', { decimal: true, step: '2', min: '2' }).ok, true);
  assert.equal(checkQuantity('3', { decimal: true, step: '2', min: '2' }).error, 'step');
  assert.equal(checkQuantity('0.1', { decimal: true, step: '0.1', min: '0.1' }).ok, true);
  assert.equal(checkQuantity('1', undefined).ok, true, 'no rule: whole numbers from one');
});

test('a quantity over the ceiling is refused, also when it is reached by adding', () => {
  assert.equal(checkQuantity('1000001', WHOLE).error, 'too_large');
  const full = addToCart([], A, '1000000', WHOLE);
  assert.equal(full.ok, true);
  assert.equal(addToCart(full.lines, A, '1', WHOLE).error, 'too_large');
});

test('the cart holds at most 50 lines, but an existing line can still grow', () => {
  let lines = [];
  for (let index = 0; index < MAX_LINES; index += 1) {
    const id = `00000000-0000-4000-8000-${String(index).padStart(12, '0')}`;
    const result = addToCart(lines, id, '1', WHOLE);
    assert.equal(result.ok, true, `line ${index + 1}`);
    lines = result.lines;
  }
  assert.equal(lines.length, 50);
  const extra = addToCart(lines, A, '1', WHOLE);
  assert.equal(extra.ok, false);
  assert.equal(extra.error, 'cart_full');
  assert.equal(addToCart(lines, lines[0].variant_id, '1', WHOLE).ok, true);
});

test('a bad variant id is refused', () => {
  for (const bad of ['', 'not-a-uuid', '1', null, 5, '../../etc/passwd']) {
    assert.equal(addToCart([], bad, '1', WHOLE).error, 'invalid_variant', String(bad));
  }
});

test('setting a quantity replaces it and follows the rule; removing drops the line', () => {
  const lines = addToCart(addToCart([], A, '2', WHOLE).lines, B, '1', WHOLE).lines;
  assert.deepEqual(setQuantity(lines, A, '7', WHOLE).lines[0], { variant_id: A, quantity: '7' });
  assert.equal(setQuantity(lines, A, '1.5', WHOLE).error, 'whole_number');
  assert.equal(setQuantity(lines, '33333333-3333-4333-8333-333333333333', '1', WHOLE).error, 'not_in_cart');
  assert.deepEqual(removeLine(lines, A), [{ variant_id: B, quantity: '1' }]);
  assert.deepEqual(removeLine(lines, '33333333-3333-4333-8333-333333333333'), lines);
});

test('what is stored is exactly the version and lines of variant id and quantity', () => {
  const lines = addToCart([], A, '2', WHOLE).lines;
  const stored = JSON.parse(serializeCart(lines));
  assert.deepEqual(stored, { version: STORAGE_VERSION, lines: [{ variant_id: A, quantity: '2' }] });
  assert.deepEqual(Object.keys(stored).sort(), ['lines', 'version']);
  assert.deepEqual(Object.keys(stored.lines[0]).sort(), ['quantity', 'variant_id']);
});

test('nothing but variant ids and quantities is ever stored, even if a line carries more', () => {
  const tainted = [{ variant_id: A, quantity: '2', unit_price: '1.00', name: 'Chair', email: 'a@b.test', token: 'secret' }];
  const text = serializeCart(tainted);
  assert.ok(!/unit_price|price|name|email|token|secret|Chair/.test(text), text);

  const storage = fakeStorage({
    [storageKey('acme')]: JSON.stringify({ version: 1, lines: [{ variant_id: A, quantity: '2', unit_price: '0.01', name: 'Chair' }] }),
  });
  const loaded = loadCart(storage, 'acme');
  assert.deepEqual(loaded, [{ variant_id: A, quantity: '2' }], 'a pasted price is dropped on reading');
  assert.equal(saveCart(storage, 'acme', loaded), true);
  assert.ok(!storage.values.get(storageKey('acme')).includes('unit_price'), 'and it never survives a save');
});

test('a cart saved for one organization is invisible to another', () => {
  const storage = fakeStorage();
  saveCart(storage, 'acme', [{ variant_id: A, quantity: '1' }]);
  assert.equal(loadCart(storage, 'other').length, 0);
  assert.equal(loadCart(storage, 'acme').length, 1);
});

test('corrupt, wrong-version or oversized storage is discarded safely', () => {
  const line = { variant_id: A, quantity: '1' };
  const cases = {
    'not JSON': '{oops',
    'empty text': '',
    'null': 'null',
    'a number': '5',
    'an array': '[]',
    'a string': '"cart"',
    'wrong version': JSON.stringify({ version: 2, lines: [line] }),
    'no version': JSON.stringify({ lines: [line] }),
    'version as text': JSON.stringify({ version: '1', lines: [line] }),
    'lines not a list': JSON.stringify({ version: 1, lines: { a: line } }),
    'a line that is not an object': JSON.stringify({ version: 1, lines: ['x'] }),
    'a line without a variant id': JSON.stringify({ version: 1, lines: [{ quantity: '1' }] }),
    'a bad variant id': JSON.stringify({ version: 1, lines: [{ variant_id: 'nope', quantity: '1' }] }),
    'a zero quantity': JSON.stringify({ version: 1, lines: [{ variant_id: A, quantity: '0' }] }),
    'a negative quantity': JSON.stringify({ version: 1, lines: [{ variant_id: A, quantity: '-1' }] }),
    'a text quantity': JSON.stringify({ version: 1, lines: [{ variant_id: A, quantity: 'many' }] }),
    'a huge quantity': JSON.stringify({ version: 1, lines: [{ variant_id: A, quantity: '99999999' }] }),
    'the same variant twice': JSON.stringify({ version: 1, lines: [line, line] }),
    'more than 50 lines': JSON.stringify({
      version: 1,
      lines: Array.from({ length: 51 }, (_, index) => ({ variant_id: `00000000-0000-4000-8000-${String(index).padStart(12, '0')}`, quantity: '1' })),
    }),
  };
  for (const [name, raw] of Object.entries(cases)) {
    assert.deepEqual(parseCart(raw), [], name);
  }
  assert.deepEqual(parseCart(undefined), []);
  assert.deepEqual(parseCart(null), []);
});

test('storage that throws is an empty cart and a refused save reports false', () => {
  const broken = {
    getItem() { throw new Error('blocked'); },
    setItem() { throw new Error('quota'); },
    removeItem() { throw new Error('blocked'); },
  };
  assert.deepEqual(loadCart(broken, 'acme'), []);
  assert.equal(saveCart(broken, 'acme', [{ variant_id: A, quantity: '1' }]), false);
});

test('an empty cart removes the key instead of storing an empty list', () => {
  const storage = fakeStorage();
  saveCart(storage, 'acme', [{ variant_id: A, quantity: '1' }]);
  assert.ok(storage.values.has(storageKey('acme')));
  saveCart(storage, 'acme', []);
  assert.ok(!storage.values.has(storageKey('acme')));
});

test('a stored quantity is normalised ("2.000" becomes "2")', () => {
  const raw = JSON.stringify({ version: 1, lines: [{ variant_id: A, quantity: '2.000' }] });
  assert.deepEqual(parseCart(raw), [{ variant_id: A, quantity: '2' }]);
});
