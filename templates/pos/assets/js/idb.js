// The real store: IndexedDB behind the interface in store.js. This is the only file that touches IndexedDB.
//
// One database per organization and POS (its name carries the organization slug), one object store per name in
// STORE_NAMES, keys supplied by the caller (out-of-line), values copied in by the browser's structured clone.
//
// A transaction here is a real IndexedDB transaction. It stays open only while requests are pending, so the callback
// given to transaction() may await tx.get/put/delete/list and nothing else. Two tabs, or a tab and a reload, can never
// both be inside a readwrite transaction on the same stores: the browser queues them. That is what makes taking the next
// receipt number safe.

import { STORE_NAMES, StoreError, assertStorable } from './store.js';

const VERSION = 1;
const FULL_MESSAGE = 'This device could not save the data. Its storage may be full or blocked.';

/** The IndexedDB database name for a POS scope such as "/{org}/pos": letters, digits and hyphens only. */
export function databaseName(scope) {
  const clean = String(scope).replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '');
  return `gfh-pos-${clean === '' ? 'default' : clean}`;
}

function wrap(error) {
  if (error instanceof StoreError || error?.name === 'SensitiveDataError') return error;
  return new StoreError(FULL_MESSAGE, error);
}

function request(idbRequest) {
  return new Promise((resolve, reject) => {
    idbRequest.onsuccess = () => resolve(idbRequest.result);
    idbRequest.onerror = () => reject(idbRequest.error ?? new Error('The storage request failed.'));
  });
}

/**
 * Opens (and on first use creates) the database. Rejects with a StoreError when IndexedDB is missing or blocked.
 * @param {{scope: string, indexedDB?: IDBFactory}} options
 */
export async function openIdbStore({ scope, indexedDB = globalThis.indexedDB }) {
  if (!indexedDB) throw new StoreError('This browser has no local database (IndexedDB).');

  const db = await new Promise((resolve, reject) => {
    let opened;
    try {
      opened = indexedDB.open(databaseName(scope), VERSION);
    } catch (error) {
      reject(wrap(error));
      return;
    }
    opened.onupgradeneeded = () => {
      for (const name of STORE_NAMES) {
        if (!opened.result.objectStoreNames.contains(name)) opened.result.createObjectStore(name);
      }
    };
    opened.onsuccess = () => resolve(opened.result);
    opened.onerror = () => reject(wrap(opened.error));
    opened.onblocked = () => reject(new StoreError('The local database is blocked by another tab. Close the other POS tabs and reload.'));
  });
  // A newer version of the app in another tab wants to upgrade: step aside.
  db.onversionchange = () => db.close();

  /** The four methods over one IDBTransaction. */
  function over(tx) {
    return {
      get: (name, key) => request(tx.objectStore(name).get(key)),
      list: (name) => request(tx.objectStore(name).getAll()),
      put: async (name, key, value) => {
        assertStorable(value, `${name}.${key}`);
        await request(tx.objectStore(name).put(value, key));
      },
      delete: async (name, key) => {
        await request(tx.objectStore(name).delete(key));
      },
    };
  }

  async function transaction(scopeNames, mode, fn) {
    let tx;
    try {
      tx = db.transaction(scopeNames, mode === 'readwrite' ? 'readwrite' : 'readonly');
    } catch (error) {
      throw wrap(error);
    }

    const finished = new Promise((resolve, reject) => {
      tx.oncomplete = () => resolve();
      tx.onabort = () => reject(tx.error ?? new Error('The storage transaction was cancelled.'));
      tx.onerror = () => {}; // the abort handler reports it
    });
    finished.catch(() => {}); // never an unhandled rejection; the awaits below report it

    let result;
    try {
      result = await fn(over(tx));
    } catch (error) {
      try {
        tx.abort();
      } catch {
        // Already finished or aborted.
      }
      await finished.catch(() => {});
      throw wrap(error);
    }

    try {
      await finished;
    } catch (error) {
      throw wrap(error);
    }
    return result;
  }

  return {
    get: (name, key) => transaction([name], 'readonly', (tx) => tx.get(name, key)),
    list: (name) => transaction([name], 'readonly', (tx) => tx.list(name)),
    put: (name, key, value) => transaction([name], 'readwrite', (tx) => tx.put(name, key, value)),
    delete: (name, key) => transaction([name], 'readwrite', (tx) => tx.delete(name, key)),
    transaction,
    close: () => db.close(),
  };
}
