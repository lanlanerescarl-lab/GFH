// What the POS remembers in localStorage, and nothing else: the last terminal chosen on this device (an id, so the next
// start skips the choice). It is a convenience, never authentication. Sales, the shift and the snapshot live in the
// IndexedDB store (store.js, idb.js), not here. No token, password, session or user data is ever written. Storage can be
// blocked or full; every call copes.

export function createStorage(scope, local = safe(() => globalThis.localStorage)) {
  const terminalKey = `gfh.pos.terminal:${scope}`;

  return {
    lastTerminal() {
      return read(local, terminalKey);
    },

    rememberTerminal(terminalId) {
      write(local, terminalKey, terminalId);
    },

    forgetTerminal() {
      remove(local, terminalKey);
    },
  };
}

function safe(getter) {
  try {
    return getter() ?? null;
  } catch {
    return null;
  }
}

function read(store, key) {
  try {
    return store?.getItem(key) ?? null;
  } catch {
    return null;
  }
}

function write(store, key, value) {
  try {
    store?.setItem(key, value);
  } catch {
    // Blocked or full: the convenience is simply lost.
  }
}

function remove(store, key) {
  try {
    store?.removeItem(key);
  } catch {
    // Nothing to do.
  }
}
