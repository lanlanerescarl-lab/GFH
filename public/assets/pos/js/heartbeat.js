// Whether the server can really be reached. navigator.onLine only says there is a network interface; a dead
// connection (a router with no internet, a captive portal) still reads "online". So the Online pill combines three
// signals:
//   - navigator.onLine           false means offline at once;
//   - every real request         api.js reports each one's outcome through report(), so the answer is always fresh while the POS is busy;
//   - a light ping every 20 s    only when idle, meaning no request reached the server in the last 20 s.
// A ping that gets any answer below 500 proves the server is reachable (even a 401 does). Pure logic: the clock, the
// timers, the ping and the browser's own flag are all passed in, so the tests drive it with a fake clock.

export const HEARTBEAT_MS = 20 * 1000;

const realTimers = {
  setInterval: (...args) => globalThis.setInterval(...args),
  clearInterval: (...args) => globalThis.clearInterval(...args),
};

/**
 * @param {object} options
 * @param {() => Promise<boolean>} options.ping resolves true when the server answered, false (or rejects) when not
 * @param {() => boolean} [options.browserOnline] navigator.onLine
 * @param {(online: boolean) => void} [options.onChange] told only when the combined answer changes
 * @param {() => number} [options.now]
 * @param {typeof realTimers} [options.timers]
 * @param {number} [options.intervalMs]
 */
export function createHeartbeat({ ping, browserOnline = () => globalThis.navigator?.onLine !== false, onChange = () => {}, now = () => Date.now(), timers = realTimers, intervalMs = HEARTBEAT_MS }) {
  let reachable = true;
  let lastContactAt = now();
  let shown = browserOnline() && reachable;
  let timer = null;
  let pinging = false;

  function online() {
    return browserOnline() && reachable;
  }

  function emit() {
    const value = online();
    if (value === shown) return;
    shown = value;
    onChange(value);
  }

  /** A request just finished (api.js calls this for every one): true if the server answered, false if it could not be reached. */
  function report(answered) {
    lastContactAt = now();
    reachable = answered;
    emit();
  }

  async function check() {
    if (pinging || !browserOnline()) return;
    pinging = true;
    try {
      report(Boolean(await ping()));
    } catch {
      report(false);
    } finally {
      pinging = false;
    }
  }

  return {
    online,
    report,

    /** The browser's online or offline event: recompute at once, and when it came back, check for real straight away. */
    browserChanged() {
      emit();
      return browserOnline() ? check() : Promise.resolve();
    },

    /** One tick of the timer: ping only when nothing has reached the server for a whole interval. */
    async tick() {
      if (now() - lastContactAt < intervalMs) return;
      await check();
    },

    check,

    start() {
      if (timer !== null) return;
      timer = timers.setInterval(() => {
        this.tick();
      }, intervalMs);
    },

    stop() {
      if (timer !== null) timers.clearInterval(timer);
      timer = null;
    },
  };
}
