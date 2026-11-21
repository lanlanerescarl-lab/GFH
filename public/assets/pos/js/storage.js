// What the POS remembers in the browser, and nothing else. Both are conveniences, never authentication:
//   localStorage    the last terminal chosen on this device (an id, so the next start skips the choice);
//   sessionStorage  the ids of this shift's recent sales (plus the cash given and change), for the Receipts screen.
// No token, password, session or user data is ever written. Storage can be blocked or full; every call copes.

const MAX_SALES = 50;

export function createStorage(scope, local = safe(() => globalThis.localStorage), session = safe(() => globalThis.sessionStorage)) {
  const terminalKey = `gfh.pos.terminal:${scope}`;
  const salesKey = (shiftId) => `gfh.pos.sales:${scope}:${shiftId}`;

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

    /** The sales of a shift made on this screen, newest first: [{id, tendered, change}]. */
    sales(shiftId) {
      try {
        const list = JSON.parse(read(session, salesKey(shiftId)) ?? '[]');
        return Array.isArray(list) ? list.filter((entry) => typeof entry?.id === 'string') : [];
      } catch {
        return [];
      }
    },

    addSale(shiftId, entry) {
      const rest = this.sales(shiftId).filter((existing) => existing.id !== entry.id);
      write(session, salesKey(shiftId), JSON.stringify([entry, ...rest].slice(0, MAX_SALES)));
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
