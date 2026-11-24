import test from 'node:test';
import assert from 'node:assert/strict';
import { createMemoryStore, assertStorable, SensitiveDataError, StoreError, STORE_NAMES } from '../../templates/pos/assets/js/store.js';
import { databaseName } from '../../templates/pos/assets/js/idb.js';

test('the store has the four object stores of the design', () => {
  assert.deepEqual(STORE_NAMES, ['snapshot', 'shift', 'queue', 'meta']);
});

test('get, put, delete and list behave, and values are copies', async () => {
  const store = createMemoryStore();
  const value = { n: 1, inner: { a: [1, 2] } };
  await store.put('queue', 'b', value);
  await store.put('queue', 'a', { n: 2 });
  value.inner.a.push(3);

  assert.deepEqual(await store.get('queue', 'b'), { n: 1, inner: { a: [1, 2] } }, 'the stored value did not change with the original');
  assert.equal(await store.get('queue', 'missing'), undefined);
  assert.deepEqual((await store.list('queue')).map((row) => row.n), [2, 1], 'listed in key order');
  const read = await store.get('queue', 'b');
  read.n = 99;
  assert.equal((await store.get('queue', 'b')).n, 1, 'the value read is a copy too');
  await store.delete('queue', 'b');
  assert.equal(await store.get('queue', 'b'), undefined);
});

test('a transaction is all or nothing', async () => {
  const store = createMemoryStore();
  await store.put('meta', 'x', { v: 1 });
  await assert.rejects(store.transaction(['meta', 'queue'], 'readwrite', async (tx) => {
    await tx.put('meta', 'x', { v: 2 });
    await tx.put('queue', 'q', { v: 1 });
    throw new Error('stop');
  }), /stop/);
  assert.deepEqual(await store.get('meta', 'x'), { v: 1 });
  assert.equal(await store.get('queue', 'q'), undefined);
});

test('transactions never overlap: ten read-increment-write transactions count to ten', async () => {
  const store = createMemoryStore();
  await store.put('meta', 'n', { v: 0 });
  await Promise.all(Array.from({ length: 10 }, () => store.transaction(['meta'], 'readwrite', async (tx) => {
    const row = await tx.get('meta', 'n');
    await tx.put('meta', 'n', { v: row.v + 1 });
  })));
  assert.equal((await store.get('meta', 'n')).v, 10);
});

test('a read-only transaction cannot write', async () => {
  const store = createMemoryStore();
  await assert.rejects(store.transaction(['meta'], 'readonly', (tx) => tx.put('meta', 'k', { v: 1 })), StoreError);
});

test('nothing sensitive can be written: tokens, passwords, CSRF values, sessions and cookies are refused at any depth', async () => {
  const store = createMemoryStore();
  for (const bad of [{ csrf_token: 'x' }, { csrf: 'x' }, { password: 'x' }, { auth: { access_token: 'x' } }, { rows: [{ session_id: 'x' }] }, { Cookie: 'x' }, { secret: 'x' }]) {
    await assert.rejects(store.put('meta', 'k', bad), SensitiveDataError, JSON.stringify(bad));
    await assert.rejects(store.transaction(['meta'], 'readwrite', (tx) => tx.put('meta', 'k', bad)), SensitiveDataError);
  }
  assert.equal(await store.get('meta', 'k'), undefined);
  assert.doesNotThrow(() => assertStorable({ id: 'sale-1', receipt_number: 'T3-000123', payments: [{ method: 'gcash', reference: 'ABC123' }], cashier_name: 'Casey' }));
});

test('the IndexedDB database name carries the organization slug', () => {
  assert.equal(databaseName('/demo/pos'), 'gfh-pos-demo-pos');
  assert.equal(databaseName('/shop/acme/pos'), 'gfh-pos-shop-acme-pos');
  assert.notEqual(databaseName('/one/pos'), databaseName('/two/pos'));
});
