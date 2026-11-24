import test from 'node:test';
import assert from 'node:assert/strict';
import { createTabLock, lockName, TabBusyError } from '../../templates/pos/assets/js/lock.js';
import { registerWorker, forgetShell, FORGET_SHELL } from '../../templates/pos/assets/js/swclient.js';
import { settle } from './support.js';

/** navigator.locks with "ifAvailable" semantics. */
function fakeLocks() {
  const held = new Set();
  return {
    held,
    async request(name, options, callback) {
      if (options.ifAvailable && held.has(name)) return callback(null);
      held.add(name);
      try { return await callback({ name }); } finally { held.delete(name); }
    },
  };
}

class FakeChannel {
  static all = new Map();
  constructor(name) { this.name = name; this.onmessage = null; (FakeChannel.all.get(name) ?? FakeChannel.all.set(name, new Set()).get(name)).add(this); }
  postMessage(data) { for (const other of FakeChannel.all.get(this.name)) if (other !== this && other.onmessage) setImmediate(() => other.onmessage({ data })); }
  close() { FakeChannel.all.get(this.name).delete(this); }
}

test('the lock is named after the organization and the terminal', () => {
  assert.equal(lockName('/demo/pos', 'term-1'), 'gfh-pos:/demo/pos:term-1');
  assert.notEqual(lockName('/demo/pos', 'term-1'), lockName('/demo/pos', 'term-2'));
  assert.notEqual(lockName('/demo/pos', 'term-1'), lockName('/other/pos', 'term-1'));
});

test('Web Locks: the first tab gets the terminal, a second tab for the same terminal is refused, another terminal is free', async () => {
  const locks = fakeLocks();
  const first = createTabLock({ locks, channelClass: null });
  const second = createTabLock({ locks, channelClass: null });

  assert.equal(await first.claim('/demo/pos', 'term-1'), true);
  assert.equal(await second.claim('/demo/pos', 'term-1'), false);
  assert.equal(await second.claim('/demo/pos', 'term-2'), true);
  first.release();
  await settle();
  assert.equal(await createTabLock({ locks, channelClass: null }).claim('/demo/pos', 'term-1'), true, 'free again once the first tab let go');
});

test('claiming another terminal lets go of the previous one', async () => {
  const locks = fakeLocks();
  const tab = createTabLock({ locks, channelClass: null });
  await tab.claim('/demo/pos', 'term-1');
  await tab.claim('/demo/pos', 'term-2');
  await settle();
  assert.equal(locks.held.has(lockName('/demo/pos', 'term-1')), false);
  assert.equal(locks.held.has(lockName('/demo/pos', 'term-2')), true);
});

test('without Web Locks a BroadcastChannel check refuses a second tab', async () => {
  FakeChannel.all.clear();
  const first = createTabLock({ locks: null, channelClass: FakeChannel, waitMs: 30 });
  const second = createTabLock({ locks: null, channelClass: FakeChannel, waitMs: 30 });
  assert.equal(await first.claim('/demo/pos', 'term-1'), true);
  assert.equal(await second.claim('/demo/pos', 'term-1'), false);
  assert.equal(await second.claim('/demo/pos', 'term-2'), true, 'a different terminal is not held');
});

test('with neither, the cashier is not blocked', async () => {
  assert.equal(await createTabLock({ locks: null, channelClass: null }).claim('/demo/pos', 'term-1'), true);
});

test('the refusal message is plain', () => {
  assert.match(new TabBusyError().message, /already open in another tab/i);
});

// ---------------------------------------------------------------- the page's side of the worker

test('registration uses the explicit scope /{org}/pos, after load, and never throws', async () => {
  const calls = [];
  const navigatorLike = { serviceWorker: { register: async (url, options) => { calls.push([url, options]); return { scope: options.scope }; } } };
  const target = { listeners: {}, addEventListener(name, fn) { this.listeners[name] = fn; } };
  const pending = registerWorker('/demo/pos', navigatorLike, target);
  if (globalThis.document?.readyState !== 'complete') {
    assert.equal(calls.length, 0, 'waits for the load event');
    target.listeners.load();
  }
  const registration = await pending;
  assert.deepEqual(calls, [['/demo/pos/sw.js', { scope: '/demo/pos' }]]);
  assert.equal(registration.scope, '/demo/pos');
});

test('a refused registration, or no service worker support, shows nothing and resolves null', async () => {
  const target = { addEventListener(name, fn) { fn(); } };
  assert.equal(await registerWorker('/demo/pos', { serviceWorker: { register: async () => { throw new Error('SecurityError'); } } }, target), null);
  assert.equal(await registerWorker('/demo/pos', {}, target), null);
  assert.equal(await registerWorker('/demo/pos', undefined, target), null);
});

test('forgetShell messages the worker with a reply port and resolves true when it answers', async () => {
  let message = null;
  const worker = { postMessage(data, ports) { message = data; ports[0].postMessage({ ok: true }); } };
  assert.equal(await forgetShell('/demo/pos', { serviceWorker: { controller: worker } }), true);
  assert.deepEqual(message, { type: FORGET_SHELL });
});

test('forgetShell falls back to the active worker of the scope, and gives up quickly without one', async () => {
  const worker = { postMessage(data, ports) { ports[0].postMessage({ ok: true }); } };
  const container = { controller: null, getRegistration: async (scope) => (scope === '/demo/pos' ? { active: worker } : undefined) };
  assert.equal(await forgetShell('/demo/pos', { serviceWorker: container }), true);
  assert.equal(await forgetShell('/demo/pos', { serviceWorker: { controller: null, getRegistration: async () => undefined } }), false);
  assert.equal(await forgetShell('/demo/pos', {}), false);
  const silent = { postMessage() {} };
  assert.equal(await forgetShell('/demo/pos', { serviceWorker: { controller: silent } }, 20), false, 'a worker that never answers does not block signing out');
});
