import test from 'node:test';
import assert from 'node:assert/strict';
import { createOutbox, retryDelayMs, RETRY_REPEAT_MS, TIMER_MS } from '../../templates/pos/assets/js/outbox.js';
import { createApi } from '../../templates/pos/assets/js/api.js';
import { createMemoryStore } from '../../templates/pos/assets/js/store.js';
import { readShift } from '../../templates/pos/assets/js/counter.js';
import { createClock, makeSale, openShiftRecord, fakeServer, apiError, networkError, settle } from './support.js';

async function rig({ server = fakeServer(), online = true } = {}) {
  const store = createMemoryStore();
  await openShiftRecord(store);
  const clock = createClock();
  const flags = { online };
  const outbox = createOutbox({ api: server, store, now: clock.now, timers: clock.timers, isOnline: () => flags.online });
  await outbox.init();
  return { store, clock, server, outbox, flags };
}

const queueOf = async (store) => (await store.list('queue'));

// ---------------------------------------------------------------- submit

test('submit saves the sale with the next receipt number and answers at once with a local result', async () => {
  const { outbox, store, flags } = await rig();
  flags.online = false; // keep the loop out of this test
  const result = await outbox.submit(makeSale(1));

  assert.deepEqual(result, { sale_id: 'sale-1', receipt_number: 'T3-000123', status: 'queued', total: '112.00', duplicate: false });
  const [record] = await queueOf(store);
  assert.equal(record.state, 'queued');
  assert.equal(record.attempts, 0);
  assert.equal(record.payload.receipt_number, 'T3-000123', 'the payload sent later carries the number');
  assert.equal((await readShift(store, 'term-1')).counter, 124, 'the counter moved on in the same transaction');
  assert.equal(outbox.pendingCount(), 1);
});

test('a sale that already has a receipt number keeps it and leaves the counter alone', async () => {
  const { outbox, store, flags } = await rig();
  flags.online = false;
  const result = await outbox.submit(makeSale(1, { receipt: 'T3-000200' }));

  assert.equal(result.receipt_number, 'T3-000200');
  assert.equal((await readShift(store, 'term-1')).counter, 123);
});

test('the same sale saved twice is one sale with one number', async () => {
  const { outbox, store, flags } = await rig();
  flags.online = false;
  const first = await outbox.submit(makeSale(1));
  const again = await outbox.submit(makeSale(1));

  assert.deepEqual(again, first);
  assert.equal((await queueOf(store)).length, 1);
  assert.equal((await readShift(store, 'term-1')).counter, 124);
});

test('simultaneous submits never share a receipt number', async () => {
  const { outbox, store, flags } = await rig();
  flags.online = false;
  const results = await Promise.all(Array.from({ length: 12 }, (_, index) => outbox.submit(makeSale(index + 1))));

  const numbers = results.map((result) => result.receipt_number);
  assert.equal(new Set(numbers).size, 12, `numbers repeated: ${numbers.join(' ')}`);
  assert.deepEqual([...numbers].sort(), Array.from({ length: 12 }, (_, index) => `T3-${String(123 + index).padStart(6, '0')}`));
  assert.equal((await readShift(store, 'term-1')).counter, 135);
});

test('a queue write that fails loses nothing and gives the number back', async () => {
  const { outbox, store, flags } = await rig();
  flags.online = false;
  store.failWhen((op) => op.kind === 'put' && op.name === 'queue');

  await assert.rejects(outbox.submit(makeSale(1)), (error) => error.storage === true && /could not save the sale/i.test(error.message));
  assert.equal((await queueOf(store)).length, 0);
  assert.equal((await readShift(store, 'term-1')).counter, 123, 'the failed attempt used no number');

  const result = await outbox.submit(makeSale(1)); // Retry with the same sale
  assert.equal(result.receipt_number, 'T3-000123');
});

test('submit without an open shift on this device refuses instead of inventing a number', async () => {
  const store = createMemoryStore();
  const outbox = createOutbox({ api: fakeServer(), store, isOnline: () => false });
  await assert.rejects(outbox.submit(makeSale(1)), (error) => error.name === 'NoShiftError');
});

test('the receipt fields are built inside the save, with the number the sale took', async () => {
  const { outbox, store, flags } = await rig();
  flags.online = false;
  await outbox.submit(makeSale(1), { buildReceipt: (number) => ({ receiptNumber: number }), cash: { cashTendered: 20000, change: 8800 } });

  const [record] = await queueOf(store);
  assert.deepEqual(record.receipt, { receiptNumber: 'T3-000123' });
  assert.deepEqual(record.cash, { cashTendered: 20000, change: 8800 });
});

// ---------------------------------------------------------------- the loop

test('sales are sent one at a time in receipt-number order, not in the order the store lists them', async () => {
  const { outbox, store, server, flags } = await rig();
  flags.online = false;
  // Sale ids sort the other way round from the receipt numbers they get, so key order is not receipt order.
  await outbox.submit(makeSale('c'));
  await outbox.submit(makeSale('b'));
  await outbox.submit(makeSale('a'));
  assert.deepEqual((await queueOf(store)).map((record) => record.receipt_number), ['T3-000125', 'T3-000124', 'T3-000123'], 'the store lists by key');

  await outbox.sync({ force: true });
  assert.deepEqual(server.calls, ['T3-000123', 'T3-000124', 'T3-000125']);
  assert.equal(outbox.pendingCount(), 0);
});

test('a 201 removes the sale from the queue and keeps a compact record for reprints', async () => {
  const server = fakeServer({ review: ['sale-2'] });
  const { outbox, store, flags } = await rig({ server });
  flags.online = false;
  await outbox.submit(makeSale(1), { buildReceipt: (number) => ({ receiptNumber: number }) });
  await outbox.submit(makeSale(2), { buildReceipt: (number) => ({ receiptNumber: number }) });
  await outbox.sync({ force: true });

  assert.equal((await queueOf(store)).length, 0);
  const sold = (await store.list('meta')).filter((entry) => entry.kind === 'sale-record');
  assert.equal(sold.length, 2);
  assert.equal(sold[0].payload, undefined, 'no sale payload is kept once the server has it');
  assert.deepEqual(sold.map((entry) => [entry.receipt_number, entry.needs_review]), [['T3-000123', false], ['T3-000124', true]]);
  assert.deepEqual(sold[0].receipt, { receiptNumber: 'T3-000123' });
  const shown = await outbox.shiftSales('shift-1');
  assert.deepEqual(shown.map((sale) => sale.state), ['synced', 'synced']);
});

test('an idempotent duplicate answer also removes the sale', async () => {
  const server = fakeServer();
  const { outbox, store, flags } = await rig({ server });
  flags.online = false;
  await outbox.submit(makeSale(1));
  await server.postSale((await queueOf(store))[0].payload); // the server already has it (an earlier run got through)

  await outbox.sync({ force: true });
  assert.equal((await queueOf(store)).length, 0);
  assert.equal(server.sales.size, 1);
  assert.equal((await store.list('meta')).find((entry) => entry.kind === 'sale-record').duplicate, true);
});

test('a 5xx keeps the sale and backs off on the exact schedule 5 s, 15 s, 45 s, then every 2 minutes', async () => {
  const server = fakeServer({ script: Array.from({ length: 5 }, () => apiError(503, 'Down.')) });
  const { outbox, clock, store } = await rig({ server });
  outbox.start();
  const t0 = clock.now();
  await outbox.submit(makeSale(1));
  await outbox.idle();

  const failedAt = [];
  const watch = async () => { failedAt.push(clock.now() - t0); };
  assert.equal(server.calls.length, 1);
  assert.equal(outbox.state().nextAllowedAt - clock.now(), 5000);

  await clock.advance(4999);
  assert.equal(server.calls.length, 1, 'nothing before 5 s');
  await clock.advance(1);
  assert.equal(server.calls.length, 2);
  assert.equal(outbox.state().nextAllowedAt - clock.now(), 15000);

  await clock.advance(14999);
  assert.equal(server.calls.length, 2, 'the 30 s timer does not cut a backoff short either');
  await clock.advance(1);
  assert.equal(server.calls.length, 3);
  assert.equal(outbox.state().nextAllowedAt - clock.now(), 45000);

  await clock.advance(45000);
  assert.equal(server.calls.length, 4);
  assert.equal(outbox.state().nextAllowedAt - clock.now(), RETRY_REPEAT_MS);
  await clock.advance(RETRY_REPEAT_MS);
  assert.equal(server.calls.length, 5);
  assert.equal(outbox.state().nextAllowedAt - clock.now(), RETRY_REPEAT_MS, 'and then every two minutes');

  await clock.advance(RETRY_REPEAT_MS); // the 6th try works
  assert.equal(server.calls.length, 6);
  assert.equal((await queueOf(store)).length, 0);
  assert.equal(outbox.pendingCount(), 0);
  void watch;
});

test('retryDelayMs is the schedule itself', () => {
  assert.deepEqual([1, 2, 3, 4, 9].map(retryDelayMs), [5000, 15000, 45000, 120000, 120000]);
  assert.equal(retryDelayMs(0), 0);
  assert.equal(TIMER_MS, 30000);
});

test('a network error keeps the sale and counts the attempt', async () => {
  const server = fakeServer({ script: [networkError()] });
  const { outbox, store } = await rig({ server });
  await outbox.submit(makeSale(1));
  await outbox.idle();

  const [record] = await queueOf(store);
  assert.equal(record.state, 'queued');
  assert.equal(record.attempts, 1);
  assert.match(record.last_error, /cannot reach/i);
  assert.equal(outbox.pendingCount(), 1);
});

test('a 409 and a 422 move that sale to needs attention with the server\'s message, and later sales carry on', async () => {
  const server = fakeServer({ rules: { 'T3-000123': apiError(409, 'That receipt number is already used.'), 'T3-000124': apiError(422, 'Check the payment.', { fields: { 'payments.0.amount': 'Payments must add up to the total.' } }) } });
  const { outbox, store, flags } = await rig({ server });
  flags.online = false;
  for (const n of [1, 2, 3]) await outbox.submit(makeSale(n));
  await outbox.sync({ force: true });

  assert.deepEqual(server.calls, ['T3-000123', 'T3-000124', 'T3-000125'], 'the third was still sent');
  const left = await queueOf(store);
  assert.deepEqual(left.map((record) => [record.receipt_number, record.state]), [['T3-000123', 'needs_attention'], ['T3-000124', 'needs_attention']]);
  assert.match(left[0].last_error, /already used/);
  assert.match(left[1].last_error, /Check the payment\. Payments must add up/);
  assert.equal(outbox.pendingCount(), 2, 'needs attention still counts as waiting');
  assert.equal(outbox.counts().attention, 2);

  const before = server.calls.length;
  await outbox.sync({ force: true });
  assert.equal(server.calls.length, before, 'a sale that needs attention is not sent again by the loop');
});

test('Retry puts a needs-attention sale back in line once the cause is fixed', async () => {
  const server = fakeServer({ rules: { 'T3-000123': apiError(409, 'Used.') } });
  const { outbox, store, flags } = await rig({ server });
  flags.online = false;
  await outbox.submit(makeSale(1));
  await outbox.sync({ force: true });
  assert.equal((await queueOf(store))[0].state, 'needs_attention');

  delete server.rules['T3-000123']; // a manager fixed the cause
  await outbox.retry('sale-1');
  assert.equal((await queueOf(store)).length, 0);
  assert.equal(server.sales.size, 1);
});

test('nothing in the outbox can delete an unsent sale', () => {
  const outbox = createOutbox({ api: fakeServer(), store: createMemoryStore() });
  assert.equal(Object.keys(outbox).some((name) => /delete|remove|discard|drop|cancel/i.test(name)), false);
});

test('a 401 pauses the loop; the timer does not push on; resume() sends after sign-in', async () => {
  const server = fakeServer({ script: [apiError(401, 'Sign in.')] });
  const { outbox, clock, store } = await rig({ server });
  outbox.start();
  await outbox.submit(makeSale(1));
  await outbox.idle();

  assert.equal(outbox.state().paused, true);
  assert.equal(server.calls.length, 1);
  await clock.advance(5 * TIMER_MS);
  assert.equal(server.calls.length, 1, 'paused: no timer wakes it');
  assert.equal((await queueOf(store))[0].state, 'queued', 'the sale is still safe in the queue');

  await outbox.resume();
  assert.equal(server.calls.length, 2);
  assert.equal(outbox.state().paused, false);
  assert.equal((await queueOf(store)).length, 0);
});

test('with the real api.js a 401 opens the sign-in dialog, and the same sale is sent again after sign-in', async () => {
  const posts = [];
  let signedIn = false;
  let dialogs = 0;
  const fetchImpl = async (url, init) => {
    const respond = (status, body) => ({ status, json: async () => body });
    if (url.endsWith('/auth/staff/csrf')) return respond(200, { ok: true, data: { csrf_token: 'tok-fresh' } });
    if (url.endsWith('/auth/staff/login')) { signedIn = true; return respond(200, { ok: true, data: { csrf_token: 'tok-after', user: { id: 'user-1', full_name: 'Casey' } } }); }
    posts.push(JSON.parse(init.body).receipt_number);
    return signedIn
      ? respond(201, { ok: true, data: { sale_id: 'sale-1', receipt_number: 'T3-000123', status: 'completed', total: '112.00', duplicate: false } })
      : respond(401, { ok: false, error: { code: 'unauthorized', message: 'Sign in.', fields: {} } });
  };
  const api = createApi({ base: '/demo/api/v1', csrf: 'tok-0', fetchImpl, requestSignIn: async (instance) => { dialogs += 1; await instance.login('a@b.c', 'pw'); } });
  const store = createMemoryStore();
  await openShiftRecord(store);
  const outbox = createOutbox({ api, store });
  await outbox.init();

  await outbox.submit(makeSale(1));
  await outbox.idle();
  assert.equal(dialogs, 1);
  assert.deepEqual(posts, ['T3-000123', 'T3-000123']);
  assert.equal((await queueOf(store)).length, 0);
});

test('a crash between the server taking a sale and the queue letting it go resends it, and that is harmless', async () => {
  const server = fakeServer();
  const store = createMemoryStore();
  await openShiftRecord(store);
  const first = createOutbox({ api: server, store, isOnline: () => false });
  await first.submit(makeSale(1));

  // The server accepts, then the tab dies before the sale leaves the queue.
  store.failWhen((op) => op.kind === 'delete' && op.name === 'queue');
  await first.sync({ force: true });
  assert.equal(server.sales.size, 1);
  assert.equal((await queueOf(store)).length, 1, 'still queued');

  // A fresh page: a new outbox over the same store.
  const second = createOutbox({ api: server, store, isOnline: () => true });
  await second.init();
  assert.equal(second.pendingCount(), 1);
  await second.sync({ force: true });

  assert.equal(server.calls.length, 2, 'sent twice');
  assert.equal(server.sales.size, 1, 'but the server holds one sale');
  assert.equal((await queueOf(store)).length, 0);
});

test('automatic runs skip while offline; Sync now and the online event force one', async () => {
  const { outbox, server, flags } = await rig({ online: false });
  await outbox.submit(makeSale(1));
  await outbox.idle();
  assert.equal(server.calls.length, 0, 'offline: not even tried');

  flags.online = true;
  await outbox.sync({ force: true });
  assert.equal(server.calls.length, 1);
});

test('the 30 second timer wakes the loop while sales wait, and stays quiet when nothing waits', async () => {
  const { outbox, clock, server, flags } = await rig({ online: false });
  outbox.start();
  await outbox.submit(makeSale(1));
  await clock.advance(TIMER_MS);
  assert.equal(server.calls.length, 0, 'offline at the tick');

  flags.online = true;
  await clock.advance(TIMER_MS);
  assert.equal(server.calls.length, 1);
  await clock.advance(5 * TIMER_MS);
  assert.equal(server.calls.length, 1, 'queue empty: nothing more');
  outbox.stop();
});

test('a sale made while a run is in progress is picked up by that run', async () => {
  const server = fakeServer();
  const slow = { ...server, postSale: async (payload) => { await settle(); return server.postSale(payload); } };
  const store = createMemoryStore();
  await openShiftRecord(store);
  const outbox = createOutbox({ api: slow, store });
  await outbox.init();

  await outbox.submit(makeSale(1));
  await outbox.submit(makeSale(2)); // arrives while sale 1 is being sent
  await outbox.idle();
  assert.deepEqual(server.calls, ['T3-000123', 'T3-000124']);
});

// ---------------------------------------------------------------- pendingCount and the store's contents

test('pendingCount counts queued, sending and needs-attention sales, and the rest of the interface reports the same', async () => {
  const server = fakeServer({ rules: { 'T3-000124': apiError(409, 'Used.') } });
  const { outbox, flags } = await rig({ server });
  flags.online = false;
  assert.equal(outbox.pendingCount(), 0);
  await outbox.submit(makeSale(1));
  await outbox.submit(makeSale(2));
  assert.equal(outbox.pendingCount(), 2);

  await outbox.sync({ force: true });
  assert.equal(outbox.pendingCount(), 1, 'one sent, one needing attention');
  assert.deepEqual(outbox.counts(), { waiting: 1, sendable: 0, attention: 1 });
  assert.equal((await outbox.waiting({ shiftId: 'shift-1' })).length, 1);
  assert.equal((await outbox.waiting({ shiftId: 'other' })).length, 0);
});

test('no token, password or session value ever reaches the store, whatever the api holds', async () => {
  const secret = 'tok-SECRET-1234567890';
  const api = createApi({
    base: '/demo/api/v1', csrf: secret,
    fetchImpl: async (url, init) => ({ status: 201, json: async () => ({ ok: true, data: { sale_id: JSON.parse(init.body).id, receipt_number: 'T3-000123', status: 'completed', total: '112.00', duplicate: false, csrf_token: undefined } }) }),
  });
  const { store } = await rig();
  const outbox = createOutbox({ api, store });
  await outbox.init();
  await outbox.submit(makeSale(1));
  await outbox.submit(makeSale(2));
  await outbox.idle();

  const everything = JSON.stringify(store.dump());
  assert.equal(everything.includes(secret), false);
  assert.equal(/csrf|token|password|session|cookie/i.test(everything.replace(/"counter"/g, '')), false, 'no such word even as a field name');
});
