import test from 'node:test';
import assert from 'node:assert/strict';
import { createHeartbeat, HEARTBEAT_MS } from '../../templates/pos/assets/js/heartbeat.js';
import { createApi } from '../../templates/pos/assets/js/api.js';
import { createClock } from './support.js';

function rig({ answers = [], browser = true } = {}) {
  const clock = createClock();
  const flags = { browser };
  const calls = [];
  const changes = [];
  const queue = [...answers];
  const heartbeat = createHeartbeat({
    ping: async () => { calls.push(clock.now()); const next = queue.shift(); if (next instanceof Error) throw next; return next ?? true; },
    browserOnline: () => flags.browser,
    onChange: (value) => changes.push(value),
    now: clock.now, timers: clock.timers,
  });
  return { clock, flags, calls, changes, heartbeat, queue };
}

test('when idle it pings every 20 seconds', async () => {
  const { clock, calls, heartbeat } = rig();
  heartbeat.start();
  await clock.advance(HEARTBEAT_MS * 3);
  assert.equal(calls.length, 3);
  assert.deepEqual(calls.map((at) => at - calls[0]), [0, 20000, 40000]);
  heartbeat.stop();
});

test('real traffic counts as contact: no ping while requests keep reaching the server', async () => {
  const { clock, calls, heartbeat } = rig();
  heartbeat.start();
  for (let i = 0; i < 6; i += 1) {
    await clock.advance(10000);
    heartbeat.report(true); // a sale or a refresh went through
  }
  assert.equal(calls.length, 0);
  await clock.advance(HEARTBEAT_MS);
  assert.equal(calls.length, 1, 'idle again: one ping');
});

test('the browser saying online is not enough: a dead connection is found by the heartbeat', async () => {
  const { clock, flags, changes, heartbeat } = rig({ answers: [false, false] });
  heartbeat.start();
  assert.equal(heartbeat.online(), true);
  await clock.advance(HEARTBEAT_MS);
  assert.equal(flags.browser, true, 'navigator.onLine still says online');
  assert.equal(heartbeat.online(), false);
  assert.deepEqual(changes, [false]);
});

test('a ping that throws counts as unreachable, and the pill recovers when the server answers again', async () => {
  const { clock, changes, heartbeat } = rig({ answers: [new Error('timeout'), true] });
  heartbeat.start();
  await clock.advance(HEARTBEAT_MS);
  assert.equal(heartbeat.online(), false);
  await clock.advance(HEARTBEAT_MS);
  assert.equal(heartbeat.online(), true);
  assert.deepEqual(changes, [false, true], 'told only when it changes');
});

test('the browser offline event is believed at once, and online triggers an immediate check', async () => {
  const { flags, calls, changes, heartbeat } = rig();
  flags.browser = false;
  await heartbeat.browserChanged();
  assert.equal(heartbeat.online(), false);
  assert.equal(calls.length, 0, 'no ping with no network at all');

  flags.browser = true;
  await heartbeat.browserChanged();
  assert.equal(calls.length, 1);
  assert.equal(heartbeat.online(), true);
  assert.deepEqual(changes, [false, true]);
});

test('api.ping reports reachable for any answer below 500 and unreachable otherwise', async () => {
  const make = (impl) => createApi({ base: '/demo/api/v1', csrf: 't', fetchImpl: impl });
  assert.equal(await make(async () => ({ status: 200 })).ping(), true);
  assert.equal(await make(async () => ({ status: 401 })).ping(), true, 'a 401 still proves the server is there');
  assert.equal(await make(async () => ({ status: 503 })).ping(), false);
  assert.equal(await make(async () => { throw new TypeError('Failed to fetch'); }).ping(), false);
});

test('api.ping asks for the cheap CSRF endpoint, never with a body or the token', async () => {
  let seen = null;
  await createApi({ base: '/demo/api/v1', csrf: 'tok-secret', fetchImpl: async (url, init) => { seen = { url, init }; return { status: 200 }; } }).ping();
  assert.equal(seen.url, '/demo/api/v1/auth/staff/csrf');
  assert.equal(seen.init.method, 'GET');
  assert.equal(seen.init.body, undefined);
  assert.equal(JSON.stringify(seen.init).includes('tok-secret'), false);
});
