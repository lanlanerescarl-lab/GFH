// Shared helpers for the POS JavaScript tests.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

export const modulePath = (name) => join(here, '..', '..', 'templates', 'pos', 'assets', 'js', name);

export function loadPricingFixture() {
  return JSON.parse(readFileSync(join(here, '..', 'fixtures', 'pos-pricing.json'), 'utf8'));
}

/** A snapshot product as the server's snapshot() sends it, built from a fixture product. */
export function snapshotProduct(key, p) {
  return {
    variant_id: key,
    sku: `SKU-${key}`,
    barcode: null,
    name: `Product ${key}`,
    price: p.price,
    allow_decimal_qty: p.decimals,
    qty_step: p.step,
    min_qty: p.min,
    on_hand: '100.000',
    media_id: null,
    tiers: p.tiers.map((t) => ({ label: t.label, min_qty: t.min_qty, price: t.price })),
  };
}

/** A small product for rule tests. */
export function product(overrides = {}) {
  return {
    variant_id: 'v1', sku: 'COF-1', barcode: '4800010000011', name: 'Coffee 250 g', unit: 'pc', price: '112.00',
    allow_decimal_qty: false, qty_step: '1.000', min_qty: '1.000', on_hand: '10.000', media_id: null, tiers: [], ...overrides,
  };
}

// ---------------------------------------------------------------- Batch 7.2: offline layer helpers

import { ApiError } from '../../templates/pos/assets/js/api.js';
import { saveShift } from '../../templates/pos/assets/js/counter.js';

/** Lets every pending promise callback and queued store operation run. */
export async function settle() {
  for (let i = 0; i < 25; i += 1) await new Promise((resolve) => setImmediate(resolve));
}

/** A fake clock with timers. advance(ms) runs the timers that fall due, in order, and lets their work finish. */
export function createClock(start = Date.parse('2026-11-23T08:00:00Z')) {
  let t = start;
  let sequence = 0;
  const timers = new Map();

  return {
    now: () => t,
    timers: {
      setTimeout(fn, ms) { sequence += 1; timers.set(sequence, { at: t + ms, fn, every: null }); return sequence; },
      clearTimeout(id) { timers.delete(id); },
      setInterval(fn, ms) { sequence += 1; timers.set(sequence, { at: t + ms, fn, every: ms }); return sequence; },
      clearInterval(id) { timers.delete(id); },
    },
    pending: () => timers.size,
    async advance(ms) {
      const end = t + ms;
      for (;;) {
        const due = [...timers.entries()].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
        if (due === undefined) break;
        const [id, timer] = due;
        t = timer.at;
        if (timer.every === null) timers.delete(id);
        else timer.at += timer.every;
        timer.fn();
        await settle();
      }
      t = end;
      await settle();
    },
  };
}

/** A sale as POST /pos/sales takes it, before it has a receipt number. */
export function makeSale(n, { terminalId = 'term-1', shiftId = 'shift-1', receipt = null, amount = '112.00', soldAt = null } = {}) {
  return {
    id: `sale-${n}`,
    receipt_number: receipt,
    terminal_id: terminalId,
    shift_id: shiftId,
    sold_at: soldAt ?? new Date(Date.parse('2026-11-23T08:00:00Z') + (typeof n === 'number' ? n : 100 + String(n).charCodeAt(0)) * 1000).toISOString(),
    lines: [{ variant_id: 'v1', quantity: '1.000', unit_price: amount }],
    payments: [{ method: 'cash', amount }],
  };
}

/** Stores an open shift whose first receipt number is T3-000123. */
export function openShiftRecord(store, { terminalId = 'term-1', shiftId = 'shift-1', next = 'T3-000123', code = 'T3' } = {}) {
  return saveShift(store, {
    terminalId, terminalCode: code, nextReceiptNumber: next,
    shift: { id: shiftId, terminal_id: terminalId, cashier_user_id: 'user-1', opened_at: '2026-11-23T07:00:00Z', opening_cash: '1000.00' },
    cashier: { id: 'user-1', name: 'Casey Cashier' },
  });
}

export const apiError = (status, message = 'No.', extra = {}) => new ApiError({ status, message, ...extra });
export const networkError = () => new ApiError({ network: true, message: 'Cannot reach the server.' });

/**
 * A fake server for POST /pos/sales, idempotent by sale id like the real one.
 * script: errors to throw on the next calls, in order (null means "behave"). rules: receipt number to an error that call always gets.
 */
export function fakeServer({ script = [], rules = {}, review = [] } = {}) {
  const sales = new Map();
  const calls = [];
  const queue = [...script];

  return {
    sales,
    calls,
    script: queue,
    rules,
    async postSale(payload) {
      calls.push(payload.receipt_number);
      const next = queue.shift();
      if (next) throw next;
      if (rules[payload.receipt_number]) throw rules[payload.receipt_number];
      const total = payload.payments.reduce((sum, payment) => sum + Number(payment.amount), 0).toFixed(2);
      const earlier = sales.get(payload.id);
      if (earlier) return { ...earlier, duplicate: true };
      const answer = { sale_id: payload.id, receipt_number: payload.receipt_number, status: review.includes(payload.id) ? 'needs_review' : 'completed', review_reason: review.includes(payload.id) ? 'Stock did not cover it.' : null, total, duplicate: false };
      sales.set(payload.id, answer);
      return answer;
    },
  };
}
