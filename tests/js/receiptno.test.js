import test from 'node:test';
import assert from 'node:assert/strict';
import { formatReceiptNumber, parseReceiptNumber, nextReceiptNumber } from '../../templates/pos/assets/js/receiptno.js';

test('a receipt number is the terminal code and a six-digit counter', () => {
  assert.equal(formatReceiptNumber('T3', 123), 'T3-000123');
  assert.equal(formatReceiptNumber('T3', 1), 'T3-000001');
  assert.equal(formatReceiptNumber('MAIN', 1234567), 'MAIN-1234567');
});

test('the counter is a whole number of at least one', () => {
  for (const bad of [0, -1, 1.5, 1e10, Number.NaN]) assert.throws(() => formatReceiptNumber('T3', bad), RangeError);
});

test('a number is read back into its code and counter', () => {
  assert.deepEqual(parseReceiptNumber('T3-000123'), { code: 'T3', sequence: 123 });
  assert.deepEqual(parseReceiptNumber('A1B2C3-000001'), { code: 'A1B2C3', sequence: 1 });
});

test('text that is not a receipt number is refused', () => {
  for (const bad of ['', 'T3', 'T3-123', 't3-000123', 'T3-00012a', 'TOOLONGCODE-000123', 'T3-0000000001', null, undefined]) {
    assert.equal(parseReceiptNumber(bad), null, String(bad));
  }
});

test('the next number keeps the code and the padding', () => {
  assert.equal(nextReceiptNumber('T3-000123'), 'T3-000124');
  assert.equal(nextReceiptNumber('T3-000999'), 'T3-001000');
  assert.equal(nextReceiptNumber('T3-999999'), 'T3-1000000');
  assert.equal(nextReceiptNumber('nonsense'), null);
});

test('every number made here has the shape the server accepts', () => {
  for (const n of [1, 9, 10, 99999, 100000, 123456789]) {
    assert.match(formatReceiptNumber('T3', n), /^T3-\d{6,9}$/);
  }
});
