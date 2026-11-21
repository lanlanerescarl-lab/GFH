import test from 'node:test';
import assert from 'node:assert/strict';
import {
  parseMoney, toApiMoney, formatPeso, parseQuantity, toApiQuantity, formatQuantity, parsePercent, keypadPress,
} from '../../templates/pos/assets/js/money.js';
import { parseServerTime, formatDate, formatTime, formatDateTime, formatClock } from '../../templates/pos/assets/js/datetime.js';
import { buildSale, newSaleId } from '../../templates/pos/assets/js/sale.js';

test('money text is exact in centavos', () => {
  assert.equal(parseMoney('112'), 11200);
  assert.equal(parseMoney('112.5'), 11250);
  assert.equal(parseMoney('0.07'), 7);
  assert.equal(parseMoney(' 2000.00 '), 200000);
  for (const bad of ['', '1.234', '-5', 'abc', '1,000', '1e3', '.5', '5.', null]) assert.equal(parseMoney(bad), null, String(bad));
  assert.equal(toApiMoney(11200), '112.00');
  assert.equal(toApiMoney(7), '0.07');
  assert.equal(formatPeso(123450), '\u20B11,234.50');
  assert.equal(formatPeso(100000000), '\u20B11,000,000.00');
  assert.equal(formatPeso(-550), '-\u20B15.50');
});

test('quantity text is exact in thousandths', () => {
  assert.equal(parseQuantity('2'), 2000);
  assert.equal(parseQuantity('0.25'), 250);
  assert.equal(parseQuantity('12.345'), 12345);
  for (const bad of ['', '1.2345', '-1', 'x', '1,5']) assert.equal(parseQuantity(bad), null, bad);
  assert.equal(toApiQuantity(2500), '2.500');
  assert.equal(formatQuantity(2500), '2.5');
  assert.equal(formatQuantity(4000), '4');
  assert.equal(formatQuantity(250), '0.25');
  assert.equal(parsePercent('20.00'), 2000);
  assert.equal(parsePercent('12.5'), 1250);
});

test('the keypad builds an amount with at most two decimals and no leading zeros', () => {
  const type = (keys, decimals = 2) => keys.split(' ').reduce((text, key) => keypadPress(text, key, decimals), '');
  assert.equal(type('1 2 3'), '123');
  assert.equal(type('1 2 . 5'), '12.5');
  assert.equal(type('1 2 . 5 0 9'), '12.50');
  assert.equal(type('. 5'), '0.5');
  assert.equal(type('0 5'), '5');
  assert.equal(type('1 . .'), '1.');
  assert.equal(type('1 2 back'), '1');
  assert.equal(type('1 2 clear'), '');
  assert.equal(type('1 . 5', 0), '15');
  assert.equal(keypadPress('', 'x'), '');
  assert.equal(keypadPress('123456789', '1'), '123456789');
});

test('dates are DD Mon YYYY and times are 12-hour, in Asia/Manila', () => {
  // 2026-11-20 06:05 UTC is 2:05 PM in Manila (UTC+8).
  assert.equal(formatDate('2026-11-20T06:05:09Z'), '20 Nov 2026');
  assert.equal(formatTime('2026-11-20T06:05:09Z'), '2:05 PM');
  assert.equal(formatClock('2026-11-20T06:05:09Z'), '2:05:09 PM');
  assert.equal(formatDateTime('2026-11-20T06:05:09Z'), '20 Nov 2026, 2:05 PM');
});

test('a time late in the UTC day is already the next day in Manila', () => {
  assert.equal(formatDate('2026-11-20T17:30:00Z'), '21 Nov 2026');
  assert.equal(formatTime('2026-11-20T17:30:00Z'), '1:30 AM');
  assert.equal(formatTime('2026-11-20T16:00:00Z'), '12:00 AM');
  assert.equal(formatTime('2026-11-20T04:00:00Z'), '12:00 PM');
});

test("the server's PostgreSQL-style times are read, whatever the browser", () => {
  assert.equal(parseServerTime('2026-11-20 14:05:09+08').toISOString(), '2026-11-20T06:05:09.000Z');
  assert.equal(parseServerTime('2026-11-20 14:05:09.123456+08:00').toISOString(), '2026-11-20T06:05:09.123Z');
  assert.equal(parseServerTime('2026-11-20T06:05:09Z').toISOString(), '2026-11-20T06:05:09.000Z');
  assert.equal(parseServerTime('2026-11-20 14:05:09+0530').toISOString(), '2026-11-20T08:35:09.000Z');
  assert.equal(formatDateTime('2026-11-20 14:05:09+08'), '20 Nov 2026, 2:05 PM');
  assert.equal(parseServerTime('yesterday'), null);
  assert.equal(formatDate('nonsense'), '');
});

test('a sale is built in the JSON the server expects, as exact strings', () => {
  const sale = buildSale({
    id: 'id-1', receiptNumber: 'T3-000007', terminalId: 't', shiftId: 's', soldAt: '2026-11-20T06:05:09.000Z',
    lines: [{ variantId: 'v1', quantityMilli: 2500, unitPrice: 11200 }],
    payments: [{ method: 'card', amount: 10000, reference: 'AUTH1' }, { method: 'cash', amount: 18000, reference: '' }],
    rule: { key: 'senior' }, idLast4: 'AB12',
  });

  assert.deepEqual(sale, {
    id: 'id-1', receipt_number: 'T3-000007', terminal_id: 't', shift_id: 's', sold_at: '2026-11-20T06:05:09.000Z',
    lines: [{ variant_id: 'v1', quantity: '2.500', unit_price: '112.00' }],
    payments: [{ method: 'card', amount: '100.00', reference: 'AUTH1' }, { method: 'cash', amount: '180.00' }],
    discount: { key: 'senior', id_ref_last4: 'AB12' },
  });
  assert.equal('discount' in buildSale({ id: 'x', receiptNumber: 'T3-000001', terminalId: 't', shiftId: 's', soldAt: 'z', lines: [], payments: [] }), false);
});

test('a new sale id is a version 4 UUID, also without crypto.randomUUID', () => {
  assert.match(newSaleId(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
  const bytesOnly = { getRandomValues: (array) => array.fill(255) };
  assert.match(newSaleId(bytesOnly), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
});
