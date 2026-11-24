import test from 'node:test';
import assert from 'node:assert/strict';
import { closeShiftGuard, signOutGuard, waitingSales, needsConnection, SYNC_HASH } from '../../templates/pos/assets/js/guards.js';
import { createOutbox } from '../../templates/pos/assets/js/outbox.js';
import { createMemoryStore } from '../../templates/pos/assets/js/store.js';
import { openShiftRecord, makeSale, fakeServer, apiError } from './support.js';

const rec = (state, shift = 'shift-1') => ({ state, shift_id: shift });

test('closing a shift is refused while any sale of that shift is queued, sending or needs attention', () => {
  for (const state of ['queued', 'sending', 'needs_attention']) {
    const guard = closeShiftGuard([rec(state)], 'shift-1');
    assert.equal(guard.allowed, false, state);
    assert.equal(guard.count, 1);
    assert.match(guard.message, /1 sale is of this shift still waiting/);
  }
  assert.match(closeShiftGuard([rec('queued'), rec('queued')], 'shift-1').message, /2 sales are of this shift still waiting.*Send them/);
});

test('closing is allowed when nothing of this shift waits (other shifts do not count)', () => {
  assert.deepEqual(closeShiftGuard([], 'shift-1'), { allowed: true, count: 0, message: '' });
  assert.equal(closeShiftGuard([rec('queued', 'shift-0')], 'shift-1').allowed, true);
});

test('signing out is refused while any sale at all waits', () => {
  assert.equal(signOutGuard([rec('queued', 'shift-0')]).allowed, false);
  assert.match(signOutGuard([rec('needs_attention')]).message, /still waiting to be sent\. Send it before signing out/);
  assert.equal(signOutGuard([]).allowed, true);
});

test('the refusal points at the sync screen', () => {
  assert.equal(SYNC_HASH, '#sync');
});

test('opening and closing a shift say plainly that they need the connection', () => {
  assert.match(needsConnection('Opening a shift'), /^Opening a shift needs a connection/);
  assert.match(needsConnection('Closing the shift'), /^Closing the shift needs a connection/);
});

test('the guards read the real queue: a refused sale blocks closing, sending it unblocks it', async () => {
  const server = fakeServer({ rules: { 'T3-000123': apiError(409, 'Used.') } });
  const store = createMemoryStore();
  await openShiftRecord(store);
  const outbox = createOutbox({ api: server, store, isOnline: () => false });
  await outbox.submit(makeSale(1));

  assert.equal(closeShiftGuard(await outbox.list(), 'shift-1').allowed, false);
  await outbox.sync({ force: true });
  assert.equal((await outbox.list())[0].state, 'needs_attention');
  assert.equal(closeShiftGuard(await outbox.list(), 'shift-1').allowed, false, 'needs attention still blocks');
  assert.equal(signOutGuard(await outbox.list()).allowed, false);

  delete server.rules['T3-000123'];
  await outbox.retry('sale-1');
  assert.equal(closeShiftGuard(await outbox.list(), 'shift-1').allowed, true);
  assert.equal(signOutGuard(await outbox.list()).allowed, true);
  assert.equal(waitingSales(await outbox.list()).length, 0);
});
