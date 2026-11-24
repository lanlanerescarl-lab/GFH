// The POS runs in one tab per organization and terminal. Two tabs would each keep their own idea of the shift and the
// queue (the store itself stays correct, but a cashier switching between two live screens makes mistakes), so the
// second tab is refused with a plain message.
//
// claim() takes a Web Lock named after the organization and terminal (navigator.locks, "ifAvailable", held until the tab
// closes). Where Web Locks do not exist it asks other tabs over a BroadcastChannel and waits a moment for an answer; that
// check can be fooled by two tabs opening in the very same instant, which is why Web Locks come first.

export const ANSWER_WAIT_MS = 300;

export class TabBusyError extends Error {
  constructor() {
    super('The POS is already open in another tab or window on this device. Use that one, or close it and reload this page.');
    this.name = 'TabBusyError';
  }
}

/** "gfh-pos:/{org}/pos:terminal-id": the organization (through the POS path) and the terminal. */
export function lockName(scope, terminalId) {
  return `gfh-pos:${scope}:${terminalId}`;
}

/**
 * @param {object} [options]
 * @param {{request: Function}|null} [options.locks] navigator.locks
 * @param {typeof BroadcastChannel|null} [options.channelClass]
 * @param {number} [options.waitMs]
 */
export function createTabLock({ locks = globalThis.navigator?.locks ?? null, channelClass = globalThis.BroadcastChannel ?? null, waitMs = ANSWER_WAIT_MS } = {}) {
  let release = null;

  function forget() {
    if (release !== null) release();
    release = null;
  }

  async function withWebLocks(name) {
    return new Promise((resolve) => {
      locks.request(name, { ifAvailable: true }, (lock) => {
        if (lock === null) {
          resolve(false);
          return undefined;
        }
        resolve(true);
        // Holding the lock means keeping this callback's promise open: until release() or the tab closes.
        return new Promise((done) => {
          release = done;
        });
      }).catch(() => resolve(false));
    });
  }

  async function withChannel(name) {
    const channel = new channelClass(name);
    const taken = await new Promise((resolve) => {
      const timer = setTimeout(() => resolve(false), waitMs);
      channel.onmessage = (event) => {
        if (event.data === 'held') {
          clearTimeout(timer);
          resolve(true);
        }
      };
      channel.postMessage('who-holds');
    });

    if (taken) {
      channel.close();
      return false;
    }

    channel.onmessage = (event) => {
      if (event.data === 'who-holds') channel.postMessage('held');
    };
    release = () => channel.close();
    return true;
  }

  return {
    /**
     * Claims the terminal for this tab. Resolves true when it is ours, false when another tab has it.
     * Claiming a different terminal lets go of the previous one first.
     */
    async claim(scope, terminalId) {
      forget();
      const name = lockName(scope, terminalId);
      if (locks !== null && typeof locks.request === 'function') return withWebLocks(name);
      if (channelClass !== null) return withChannel(name);
      return true; // Neither exists: nothing can be checked, so do not block the cashier.
    },

    release: forget,
  };
}
