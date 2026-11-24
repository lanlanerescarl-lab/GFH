// The outbox: where a finished sale goes to reach the server. The screens use only these:
//
//   submit(sale, extras)   saves the sale on this device and returns at once with a local result
//                          {sale_id, receipt_number, status: 'queued', total, duplicate: false}
//   pendingCount()         how many sales are waiting (queued, being sent, or needing attention)
//
// Batch 7.2 (offline layer). submit() writes the sale to the queue store in one transaction that also takes the next
// receipt number from the shift's counter (counter.js), unless the sale already carries a number. If that write fails
// (storage full, blocked) it throws an error with `storage: true` and nothing is lost: the ticket stays on screen and
// the cashier presses Retry. Nothing in the screens calls api.postSale(); only the loop below does.
//
// The sync loop sends queued sales one at a time, in receipt-number order, through api.postSale():
//   201 or an idempotent "duplicate" answer   the sale leaves the queue; a compact local record stays for reprints
//   network error, 5xx, 408, 429, odd answer  the sale stays queued and the loop backs off: 5 s, 15 s, 45 s, then every 2 minutes
//   401 (sign-in dialog cancelled)            the loop pauses until resume() or Sync now
//   409, 422 and other refusals               the sale goes to "needs attention" with the server's message; later sales carry on
// The sale id is the idempotency key, so if the tab dies after the server accepted a sale but before it left the queue,
// the next run sends it again and the server answers "duplicate". The loop runs on submit, on the online event (the app
// calls wake({force: true})), every 30 seconds while sales wait, and on Sync now.

import { ApiError } from './api.js';
import { parseMoney, toApiMoney } from './money.js';
import { parseReceiptNumber } from './receiptno.js';
import { takeReceiptNumber } from './counter.js';
import { waitingSales } from './guards.js';

export const QUEUE_STORE = 'queue';
export const META_STORE = 'meta';
export const TIMER_MS = 30 * 1000;
export const RETRY_DELAYS_MS = [5 * 1000, 15 * 1000, 45 * 1000];
export const RETRY_REPEAT_MS = 2 * 60 * 1000;
/** How many sent sales are remembered for reprints and the receipts screen. */
export const MAX_SYNCED_RECORDS = 300;

const STORAGE_MESSAGE = 'This device could not save the sale. Its storage may be full or blocked. Your ticket is safe: free some space or allow storage, then press Retry.';

/** How long to wait after the nth failure in a row (1 gives 5 s, 2 gives 15 s, 3 gives 45 s, then 2 minutes). */
export function retryDelayMs(failures) {
  if (failures <= 0) return 0;
  return failures <= RETRY_DELAYS_MS.length ? RETRY_DELAYS_MS[failures - 1] : RETRY_REPEAT_MS;
}

/** The sale could not be written to this device. `storage` lets the Pay dialog tell it from a server error. */
export class OutboxStorageError extends Error {
  constructor(cause = null) {
    super(STORAGE_MESSAGE);
    this.name = 'OutboxStorageError';
    this.storage = true;
    this.cause = cause;
  }
}

/** Receipt-number order: terminal code, then the counter, then the time the sale was saved. */
export function compareReceipts(a, b) {
  const x = parseReceiptNumber(a.receipt_number);
  const y = parseReceiptNumber(b.receipt_number);
  if (x !== null && y !== null) {
    if (x.code !== y.code) return x.code < y.code ? -1 : 1;
    if (x.sequence !== y.sequence) return x.sequence - y.sequence;
  }
  return String(a.created_at ?? '').localeCompare(String(b.created_at ?? ''));
}

/** The total of a sale in API money: the payments add up to it (the server refuses a sale whose payments do not). */
function totalOf(payload) {
  return toApiMoney((payload.payments ?? []).reduce((sum, payment) => sum + (parseMoney(String(payment.amount)) ?? 0), 0));
}

function cashOf(payload) {
  return (payload.payments ?? []).filter((payment) => payment.method === 'cash').reduce((sum, payment) => sum + (parseMoney(String(payment.amount)) ?? 0), 0);
}

/** The server's reason, with its field messages when they say more. */
export function errorText(error) {
  if (!(error instanceof ApiError)) return 'The POS could not send this sale.';
  const details = [...new Set(Object.values(error.fields ?? {}).filter((text) => typeof text === 'string'))];
  return details.length > 0 && !details.includes(error.message) ? `${error.message} ${details.join(' ')}` : error.message;
}

/** 'retry' (keep it and back off), 'signin' (pause) or 'attention' (the server refused it; stop retrying this sale). */
export function classify(error) {
  if (!(error instanceof ApiError) || error.network) return 'retry';
  if (error.status === 401) return 'signin';
  if (error.status === 408 || error.status === 429) return 'retry';
  return error.status >= 400 && error.status < 500 ? 'attention' : 'retry';
}

const realTimers = {
  setTimeout: (...args) => globalThis.setTimeout(...args),
  clearTimeout: (...args) => globalThis.clearTimeout(...args),
  setInterval: (...args) => globalThis.setInterval(...args),
  clearInterval: (...args) => globalThis.clearInterval(...args),
};

/**
 * @param {object} options
 * @param {{postSale: (sale: object) => Promise<object>}} options.api
 * @param {object} options.store the store interface (store.js)
 * @param {() => number} [options.now] milliseconds since 1970 (tests pass a fake clock)
 * @param {typeof realTimers} [options.timers]
 * @param {() => boolean} [options.isOnline] whether an automatic run should try at all
 */
export function createOutbox({ api, store, now = () => Date.now(), timers = realTimers, isOnline = () => true }) {
  let counts = { waiting: 0, sendable: 0, attention: 0 };
  let inFlight = null;
  let running = false;
  let rerun = false;
  let rerunForce = false;
  let paused = false;
  let failures = 0;
  let nextAllowedAt = 0;
  let retryTimer = null;
  let tickTimer = null;
  const listeners = new Set();

  const stamp = () => new Date(now()).toISOString();

  // ------------------------------------------------------------------ reading

  async function readQueue() {
    return (await store.list(QUEUE_STORE)).sort(compareReceipts);
  }

  function snapshotState() {
    return { ...counts, paused, failures, nextAllowedAt, running };
  }

  function notify() {
    const state = snapshotState();
    for (const listener of listeners) {
      try {
        listener(state);
      } catch {
        // A screen that fails to redraw must not stop the loop.
      }
    }
  }

  /** Re-counts the queue and tells the listeners. */
  async function refresh() {
    const records = await readQueue();
    counts = {
      waiting: waitingSales(records).length,
      sendable: records.filter((record) => record.state === 'queued' || record.state === 'sending').length,
      attention: records.filter((record) => record.state === 'needs_attention').length,
    };
    notify();
    return counts;
  }

  async function update(id, change) {
    await store.transaction([QUEUE_STORE], 'readwrite', async (tx) => {
      const record = await tx.get(QUEUE_STORE, id);
      if (record !== undefined) await tx.put(QUEUE_STORE, id, change(record));
    });
  }

  // ------------------------------------------------------------------ submit

  async function submit(sale, extras = {}) {
    let record;
    try {
      record = await store.transaction(['shift', QUEUE_STORE], 'readwrite', async (tx) => {
        // The same sale saved twice (a Retry after a lost answer) is one sale: give back what is already there.
        const existing = await tx.get(QUEUE_STORE, sale.id);
        if (existing !== undefined) return existing;

        const number = sale.receipt_number ? sale.receipt_number : await takeReceiptNumber(tx, sale.terminal_id);
        const payload = { ...sale, receipt_number: number };
        const created = {
          id: sale.id,
          payload,
          receipt_number: number,
          terminal_id: payload.terminal_id,
          shift_id: payload.shift_id,
          sold_at: payload.sold_at,
          total: totalOf(payload),
          cash_total: cashOf(payload),
          created_at: stamp(),
          state: 'queued',
          attempts: 0,
          last_error: null,
          last_attempt_at: null,
          accepted: null,
          // What the receipt shows, kept so the sale can be reprinted offline and after it is sent.
          receipt: typeof extras.buildReceipt === 'function' ? extras.buildReceipt(number) : null,
          cash: extras.cash ?? null,
        };
        await tx.put(QUEUE_STORE, sale.id, created);
        return created;
      });
    } catch (error) {
      if (error?.name === 'NoShiftError' || error?.name === 'SensitiveDataError') throw error;
      throw new OutboxStorageError(error);
    }

    await refresh().catch(() => {});
    wake().catch(() => {});

    return { sale_id: record.id, receipt_number: record.receipt_number, status: 'queued', total: record.total, duplicate: false };
  }

  // ------------------------------------------------------------------ the loop

  function scheduleRetry(delay) {
    if (retryTimer !== null) timers.clearTimeout(retryTimer);
    retryTimer = timers.setTimeout(() => {
      retryTimer = null;
      wake().catch(() => {});
    }, delay);
  }

  function resetBackoff() {
    failures = 0;
    nextAllowedAt = 0;
    if (retryTimer !== null) {
      timers.clearTimeout(retryTimer);
      retryTimer = null;
    }
  }

  function backOff() {
    failures += 1;
    const delay = retryDelayMs(failures);
    nextAllowedAt = now() + delay;
    scheduleRetry(delay);
  }

  /** The compact local record that stays after a sale is sent. */
  function sentRecord(record, answer) {
    const status = typeof answer?.status === 'string' ? answer.status : 'completed';
    return {
      kind: 'sale-record',
      id: record.id,
      receipt_number: answer?.receipt_number ?? record.receipt_number,
      status,
      needs_review: status === 'needs_review',
      review_reason: answer?.review_reason ?? null,
      total: answer?.total ?? record.total,
      duplicate: Boolean(answer?.duplicate),
      sold_at: record.sold_at,
      shift_id: record.shift_id,
      terminal_id: record.terminal_id,
      cash_total: record.cash_total,
      cash: record.cash,
      receipt: record.receipt,
      accepted_at: stamp(),
    };
  }

  async function accept(record, answer) {
    await store.transaction([QUEUE_STORE, META_STORE], 'readwrite', async (tx) => {
      await tx.delete(QUEUE_STORE, record.id);
      await tx.put(META_STORE, `sale:${record.id}`, sentRecord(record, answer));

      const kept = (await tx.list(META_STORE)).filter((entry) => entry.kind === 'sale-record').sort((a, b) => String(b.accepted_at).localeCompare(String(a.accepted_at)));
      for (const old of kept.slice(MAX_SYNCED_RECORDS)) await tx.delete(META_STORE, `sale:${old.id}`);
    });
  }

  /** Sends one sale. Returns 'sent', 'attention', 'retry' or 'signin'. */
  async function sendOne(record) {
    try {
      await update(record.id, (current) => ({ ...current, state: 'sending', attempts: current.attempts + 1, last_attempt_at: stamp() }));
    } catch {
      return 'retry';
    }
    notify();

    let answer;
    try {
      answer = await api.postSale(record.payload);
    } catch (error) {
      const kind = classify(error);
      const message = kind === 'signin' ? 'You were signed out. Sign in to send this sale.' : errorText(error);
      const state = kind === 'attention' ? 'needs_attention' : 'queued';
      await update(record.id, (current) => ({ ...current, state, last_error: message })).catch(() => {});
      return kind;
    }

    try {
      await accept(record, answer);
    } catch {
      // The server has the sale but it could not leave the queue. It stays here and is sent again; the server
      // answers "duplicate" because the sale id is the key.
      return 'retry';
    }
    return 'sent';
  }

  async function pass({ force }) {
    if (force) {
      paused = false;
    } else if (paused || !isOnline() || now() < nextAllowedAt) {
      return { sent: 0, attention: 0, stopped: paused ? 'paused' : !isOnline() ? 'offline' : 'waiting' };
    }

    const summary = { sent: 0, attention: 0, stopped: null };
    for (;;) {
      const next = (await readQueue()).find((record) => record.state === 'queued' || record.state === 'sending');
      if (next === undefined) break;

      const outcome = await sendOne(next);
      if (outcome === 'sent') {
        summary.sent += 1;
        resetBackoff();
      } else if (outcome === 'attention') {
        summary.attention += 1;
        resetBackoff();
      } else if (outcome === 'signin') {
        paused = true;
        summary.stopped = 'signin';
        break;
      } else {
        backOff();
        summary.stopped = 'retry';
        break;
      }
      await refresh().catch(() => {});
    }

    await refresh().catch(() => {});
    return summary;
  }

  async function runLoop(options) {
    let result;
    let next = options;
    try {
      for (;;) {
        rerun = false;
        rerunForce = false;
        result = await pass(next);
        if (!rerun) break;
        // A request that came in during the run keeps its force: Sync now must not be downgraded to an automatic run.
        next = { force: rerunForce };
      }
    } finally {
      // Cleared in the same turn as the last check above, so a request can never fall between "nothing more to do" and "not running".
      running = false;
    }
    return result;
  }

  /** Runs the loop (one run at a time; a request while it runs makes it look again when it ends). {force: true} ignores the pause, the connection and the backoff. */
  function sync({ force = false } = {}) {
    if (running) {
      rerun = true;
      rerunForce = rerunForce || force;
      // The running loop looks again before it ends, so this resolves after that second look.
      return inFlight;
    }
    running = true;
    const mine = runLoop({ force }).finally(() => {
      if (inFlight === mine) inFlight = null;
      notify();
    });
    inFlight = mine;
    return mine;
  }

  /** An automatic run: respects the pause, the connection and the backoff. The online event and Sync now call sync({force: true}). */
  function wake() {
    return sync({ force: false });
  }

  // ------------------------------------------------------------------ the rest of the interface

  return {
    submit,
    sync,
    wake,

    /** The number of sales waiting: queued, being sent, or needing attention. Always current; no await needed. */
    pendingCount() {
      return counts.waiting;
    },

    counts: () => ({ ...counts }),
    state: snapshotState,

    /** Re-reads the queue (call once at start). */
    init: refresh,

    /** Every queued record, in receipt-number order. */
    list: readQueue,

    /** The records that block closing the shift or signing out, read from the store right now. */
    async waiting({ shiftId = null } = {}) {
      return waitingSales(await readQueue(), { shiftId });
    },

    /** The sales of a shift, waiting and sent together, newest first. */
    async shiftSales(shiftId) {
      const queued = (await readQueue()).filter((record) => record.shift_id === shiftId).map((record) => ({
        id: record.id, receipt_number: record.receipt_number, sold_at: record.sold_at, total: record.total, cash_total: record.cash_total,
        state: record.state, attempts: record.attempts, last_error: record.last_error, needs_review: false, review_reason: null, receipt: record.receipt,
      }));
      const sent = (await store.list(META_STORE)).filter((entry) => entry.kind === 'sale-record' && entry.shift_id === shiftId).map((entry) => ({
        id: entry.id, receipt_number: entry.receipt_number, sold_at: entry.sold_at, total: entry.total, cash_total: entry.cash_total,
        state: 'synced', attempts: 0, last_error: null, needs_review: entry.needs_review, review_reason: entry.review_reason, receipt: entry.receipt,
      }));
      return [...queued, ...sent].sort((a, b) => String(b.sold_at).localeCompare(String(a.sold_at)) || String(b.receipt_number).localeCompare(String(a.receipt_number)));
    },

    /** Puts a sale back in line (after "needs attention") and sends now. Nothing here can delete a sale. */
    async retry(id) {
      await update(id, (record) => (record.state === 'needs_attention' ? { ...record, state: 'queued' } : record));
      await refresh();
      return sync({ force: true });
    },

    /** After the person signed in again. */
    resume() {
      paused = false;
      return sync({ force: true });
    },

    /** Starts the 30 second timer. */
    start() {
      if (tickTimer !== null) return;
      tickTimer = timers.setInterval(() => {
        if (counts.sendable > 0) wake().catch(() => {});
      }, TIMER_MS);
    },

    stop() {
      if (tickTimer !== null) timers.clearInterval(tickTimer);
      if (retryTimer !== null) timers.clearTimeout(retryTimer);
      tickTimer = null;
      retryTimer = null;
    },

    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },

    /** Resolves when no run is in progress. */
    idle: async () => {
      while (inFlight !== null) await inFlight.catch(() => {});
    },
  };
}
