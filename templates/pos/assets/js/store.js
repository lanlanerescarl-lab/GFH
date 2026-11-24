// The POS's local store, defined as a small interface so that every piece of offline logic (the queue, the receipt
// counter, the snapshot cache) is written once, against the interface, and never touches IndexedDB directly.
//
//   store.get(name, key)                      resolves the value, or undefined
//   store.put(name, key, value)               resolves when it is written
//   store.delete(name, key)
//   store.list(name)                          resolves every value of a store, in key order
//   store.transaction(names, mode, fn)        runs fn(tx) as one unit; tx has the same four methods;
//                                             mode is 'readonly' or 'readwrite'. All reads and writes of fn happen
//                                             together or not at all, and no other transaction on the same stores
//                                             runs in between. fn may await only tx methods (an IndexedDB
//                                             transaction closes when it has nothing pending), never a timer or fetch.
//
// Two implementations share it: idb.js (the real IndexedDB, used by the browser) and createMemoryStore() below
// (used by the Node tests). Object stores: snapshot, shift, queue, meta.
//
// Nothing secret is ever stored. assertStorable() refuses a value that carries a property named like a token, a
// password, a CSRF value, a session or a cookie; both implementations call it on every write.

export const STORE_NAMES = ['snapshot', 'shift', 'queue', 'meta'];

/** The device could not read or write its storage (full, blocked, closed). */
export class StoreError extends Error {
  constructor(message = 'This device could not use its local storage.', cause = null) {
    super(message);
    this.name = 'StoreError';
    this.cause = cause;
  }
}

/** Someone tried to write a secret into the store. A programming error, never a runtime condition. */
export class SensitiveDataError extends Error {
  constructor(path) {
    super(`The local store must never hold authentication data (${path}).`);
    this.name = 'SensitiveDataError';
  }
}

const FORBIDDEN_NAME = /token|csrf|password|passwd|secret|cookie|session|authoriz/i;

/** Throws SensitiveDataError when any property name, at any depth, looks like authentication data. Values are not inspected. */
export function assertStorable(value, path = 'value') {
  if (value === null || typeof value !== 'object') return;
  if (Array.isArray(value)) {
    value.forEach((item, index) => assertStorable(item, `${path}[${index}]`));
    return;
  }
  for (const [name, inner] of Object.entries(value)) {
    if (FORBIDDEN_NAME.test(name)) throw new SensitiveDataError(`${path}.${name}`);
    assertStorable(inner, `${path}.${name}`);
  }
}

const copy = (value) => (value === undefined ? undefined : structuredClone(value));

/**
 * An in-memory store with the same interface and the same guarantees as the IndexedDB one:
 *  - values are copied in and out (no aliasing);
 *  - a transaction is atomic (a throw inside it discards every write) and transactions never overlap;
 *  - a plain get/put outside a transaction is its own tiny transaction, so two callers that read, then write, can
 *    still interleave between the two calls, exactly as they could in a browser.
 *
 * @param {{names?: string[]}} [options]
 */
export function createMemoryStore({ names = STORE_NAMES } = {}) {
  let data = new Map(names.map((name) => [name, new Map()]));
  let chain = Promise.resolve();
  const failures = [];

  /** Runs one operation after every earlier one has finished (a queue, like IndexedDB's transaction scheduling). */
  function enqueue(operation) {
    const run = chain.then(operation);
    chain = run.catch(() => {});
    return run;
  }

  function table(source, name) {
    const found = source.get(name);
    if (found === undefined) throw new StoreError(`There is no store called "${name}".`);
    return found;
  }

  function checkFailure(op) {
    const index = failures.findIndex((entry) => entry.matches(op));
    if (index === -1) return;
    const entry = failures[index];
    if (entry.once) failures.splice(index, 1);
    throw entry.error;
  }

  /** The four methods over one body of data (read through a function, so the live data can be swapped after a commit). */
  function api(source, { writable }) {
    return {
      async get(name, key) {
        return copy(table(source(), name).get(key));
      },
      async list(name) {
        return [...table(source(), name).entries()].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)).map(([, value]) => copy(value));
      },
      async put(name, key, value) {
        if (!writable) throw new StoreError('This transaction is read-only.');
        assertStorable(value, `${name}.${key}`);
        checkFailure({ kind: 'put', name, key, value });
        table(source(), name).set(key, copy(value));
      },
      async delete(name, key) {
        if (!writable) throw new StoreError('This transaction is read-only.');
        checkFailure({ kind: 'delete', name, key });
        table(source(), name).delete(key);
      },
    };
  }

  const live = api(() => data, { writable: true });

  return {
    get: (name, key) => enqueue(() => live.get(name, key)),
    list: (name) => enqueue(() => live.list(name)),
    put: (name, key, value) => enqueue(() => live.put(name, key, value)),
    delete: (name, key) => enqueue(() => live.delete(name, key)),

    transaction(scope, mode, fn) {
      return enqueue(async () => {
        const writable = mode === 'readwrite';
        // Work on a copy of the whole data: if fn throws, nothing it wrote is kept.
        const working = new Map([...data].map(([name, rows]) => [name, new Map(rows)]));
        const result = await fn(api(() => working, { writable }));
        if (writable) data = working;
        return result;
      });
    },

    // ---- test helpers (not part of the interface the app uses)

    /** Makes the next matching write throw `error`. matches({kind, name, key, value}) picks the write; once=false keeps failing. */
    failWhen(matches, error = new StoreError('Storage is full.'), { once = true } = {}) {
      failures.push({ matches, error, once });
    },

    clearFailures() {
      failures.length = 0;
    },

    /** Everything held, as plain data, for assertions: {snapshot: {...}, shift: {...}, ...}. */
    dump() {
      return Object.fromEntries([...data].map(([name, rows]) => [name, Object.fromEntries([...rows].map(([key, value]) => [key, copy(value)]))]));
    },
  };
}
