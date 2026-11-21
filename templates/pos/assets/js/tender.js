// Tender: how a sale is paid. Pure functions, no DOM. Amounts are whole centavos.
//
// The API needs the payments to add up to the sale total exactly. A customer may hand over more cash than is due,
// so cash is recorded as the amount applied to the sale and the change is worked out separately:
//   applied cash = the smaller of the cash handed over and what is still due after the other methods.
// Other methods (card, GCash...) cannot be more than what is due: there is no change to give on them.

import { PESO } from './money.js';

const LABELS = {
  cash: 'Cash',
  gcash: 'GCash',
  maya: 'Maya',
  card: 'Card',
  bank_transfer: 'Bank transfer',
  cod: 'Cash on delivery',
};

/**
 * Methods that need a reference number. This mirrors ManualPaymentProvider::NEEDS_REFERENCE; the server is the
 * authority, so a 422 on a reference is shown on the payment even if this list is out of date.
 */
const REFERENCE_METHODS = ['gcash', 'maya', 'card', 'bank_transfer'];

export function methodLabel(method) {
  return LABELS[method] ?? method.replace(/_/g, ' ').replace(/^./, (letter) => letter.toUpperCase());
}

export function needsReference(method) {
  return REFERENCE_METHODS.includes(method);
}

/**
 * Quick cash amounts for a sale: the exact amount first, then the next round amounts above it (to 10, 50, 100,
 * 500 and 1000 pesos), smallest first, without repeats, at most four of them.
 */
export function quickAmounts(dueCentavos) {
  if (dueCentavos <= 0) return [];
  const amounts = new Set();
  for (const pesos of [10, 50, 100, 500, 1000]) {
    const step = pesos * 100;
    const rounded = Math.ceil(dueCentavos / step) * step;
    if (rounded > dueCentavos) amounts.add(rounded);
  }
  return [dueCentavos, ...[...amounts].sort((a, b) => a - b).slice(0, 4)];
}

/**
 * Works out what a set of payments does to a sale.
 *
 * @param {number} total the sale total in centavos
 * @param {Array<{method: string, amount: number, reference?: string}>} entries what the customer is paying with;
 *        for cash, the amount is what they hand over
 * @returns {{total: number, applied: number, remaining: number, change: number, cashTendered: number,
 *            payments: Array<{method: string, amount: number, reference: string}>,
 *            problems: Array<{index: number, field: string, message: string}>, complete: boolean}}
 */
export function settle(total, entries) {
  const problems = [];
  let otherApplied = 0;
  let cashTendered = 0;
  let cashReference = '';
  const others = [];

  if (total <= 0) {
    problems.push({ index: -1, field: 'total', message: 'The total is zero, so there is nothing to pay. Take the discount off or add an item.' });
  }

  entries.forEach((entry, index) => {
    if (!Number.isInteger(entry.amount) || entry.amount <= 0) {
      problems.push({ index, field: 'amount', message: 'Enter an amount.' });
      return;
    }

    if (entry.method === 'cash') {
      cashTendered += entry.amount;
      cashReference = entry.reference ?? '';
      return;
    }

    const reference = (entry.reference ?? '').trim();
    if (needsReference(entry.method) && reference === '') {
      problems.push({ index, field: 'reference', message: 'Enter the reference number for this payment.' });
    }
    if (otherApplied + entry.amount > total) {
      problems.push({ index, field: 'amount', message: `That is more than the ${PESO} still due. Only cash can be more than the amount due.` });
      return;
    }
    otherApplied += entry.amount;
    others.push({ method: entry.method, amount: entry.amount, reference });
  });

  const dueForCash = Math.max(0, total - otherApplied);
  const cashApplied = Math.min(cashTendered, dueForCash);
  const change = cashTendered - cashApplied;
  const applied = otherApplied + cashApplied;
  const remaining = Math.max(0, total - applied);

  const payments = [...others];
  if (cashApplied > 0) payments.push({ method: 'cash', amount: cashApplied, reference: cashReference });

  return {
    total, applied, remaining, change, cashTendered, payments, problems,
    complete: problems.length === 0 && total > 0 && remaining === 0 && payments.length > 0,
  };
}
