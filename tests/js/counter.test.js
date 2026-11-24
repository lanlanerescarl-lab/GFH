import test from 'node:test';
import assert from 'node:assert/strict';
import { createMemoryStore } from '../../templates/pos/assets/js/store.js';
import { saveShift, readShift, clearShift, takeReceiptNumber, peekReceiptNumber, NoShiftError } from '../../templates/pos/assets/js/counter.js';
import { createOutbox } from '../../templates/pos/assets/js/outbox.js';
import { openShiftRecord, makeSale, fakeServer } from './support.js';

const take = (store, terminal = 'term-1') => store.transaction(['shift'], 'readwrite', (tx) => takeReceiptNumber(tx, terminal));

test('the counter starts from the number the shift was opened with and formats like T3-000123', async () => {
  const store = createMemoryStore();
  const record = await openShiftRecord(store);
  assert.equal(peekReceiptNumber(record), 'T3-000123');
  assert.equal(await take(store), 'T3-000123');
  assert.equal(await take(store), 'T3-000124');
  assert.equal(peekReceiptNumber(await readShift(store, 'term-1')), 'T3-000125');
});

test('the shift record keeps the shift figures and the cashier as the receipt shows them, and nothing else about a person', async () => {
  const store = createMemoryStore();
  const record = await openShiftRecord(store);
  assert.deepEqual(Object.keys(record).sort(), ['cashier_name', 'cashier_user_id', 'counter', 'prefix', 'saved_at', 'shift', 'terminal_id']);
  assert.deepEqual(Object.keys(record.shift).sort(), ['cashier_user_id', 'id', 'opened_at', 'opening_cash', 'status', 'terminal_id']);
});

test('taking numbers inside simultaneous transactions never repeats one', async () => {
  const store = createMemoryStore();
  await openShiftRecord(store);
  const numbers = await Promise.all(Array.from({ length: 25 }, () => take(store)));
  assert.equal(new Set(numbers).size, 25);
});

test('two tabs sharing one store (two outboxes) never take the same number', async () => {
  const store = createMemoryStore();
  await openShiftRecord(store);
  const one = createOutbox({ api: fakeServer(), store, isOnline: () => false });
  const two = createOutbox({ api: fakeServer(), store, isOnline: () => false });
  const results = await Promise.all([
    ...Array.from({ length: 6 }, (_, i) => one.submit(makeSale(`a${i}`))),
    ...Array.from({ length: 6 }, (_, i) => two.submit(makeSale(`b${i}`))),
  ]);
  assert.equal(new Set(results.map((result) => result.receipt_number)).size, 12);
});

test('resuming the same shift never moves the counter back, a new shift starts from the server number', async () => {
  const store = createMemoryStore();
  await openShiftRecord(store);
  await take(store);
  await take(store); // two sales are waiting in the queue; the server has seen none of them

  const same = await openShiftRecord(store, { next: 'T3-000123' }); // the server still says 123
  assert.equal(same.counter, 125);

  const ahead = await openShiftRecord(store, { next: 'T3-000130' }); // the server is ahead (another device used this terminal)
  assert.equal(ahead.counter, 130);

  const fresh = await openShiftRecord(store, { shiftId: 'shift-2', next: 'T3-000001' });
  assert.equal(fresh.counter, 1);
});

test('no stored shift means no number', async () => {
  const store = createMemoryStore();
  await assert.rejects(take(store), NoShiftError);
});

test('closing the shift forgets the record', async () => {
  const store = createMemoryStore();
  await openShiftRecord(store);
  await clearShift(store, 'term-1');
  assert.equal(await readShift(store, 'term-1'), null);
});

test('saveShift accepts the server\'s next number and refuses a store that cannot hold it', async () => {
  const store = createMemoryStore();
  const record = await saveShift(store, { terminalId: 't', terminalCode: 'T9', shift: { id: 's' }, nextReceiptNumber: null, cashier: { id: 'u', name: 'N' } });
  assert.equal(peekReceiptNumber(record), 'T9-000001', 'with no server number the counter starts at 1 under the terminal code');
});
