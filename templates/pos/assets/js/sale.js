// Builds the JSON the server's POST /pos/sales expects. Pure functions, no DOM.
// The server recomputes every total from the unit prices and quantities sent, and the payments must add up to it.

import { toApiMoney, toApiQuantity } from './money.js';

/**
 * @param {object} input
 * @param {string} input.id the sale's own id (a UUID); the same id on a retry makes the retry safe
 * @param {string} input.receiptNumber for example "T3-000123"
 * @param {string} input.terminalId
 * @param {string} input.shiftId
 * @param {string} input.soldAt ISO 8601 time of the sale
 * @param {Array<{variantId: string, quantityMilli: number, unitPrice: number}>} input.lines priced lines
 * @param {Array<{method: string, amount: number, reference: string}>} input.payments from settle()
 * @param {{key: string}|null} input.rule the discount rule chosen for the whole sale
 * @param {string|null} input.idLast4 the last 4 characters of the customer's ID, or null
 */
export function buildSale({ id, receiptNumber, terminalId, shiftId, soldAt, lines, payments, rule = null, idLast4 = null }) {
  const sale = {
    id,
    receipt_number: receiptNumber,
    terminal_id: terminalId,
    shift_id: shiftId,
    sold_at: soldAt,
    lines: lines.map((line) => ({
      variant_id: line.variantId,
      quantity: toApiQuantity(line.quantityMilli),
      unit_price: toApiMoney(line.unitPrice),
    })),
    payments: payments.map((payment) => {
      const entry = { method: payment.method, amount: toApiMoney(payment.amount) };
      if (payment.reference) entry.reference = payment.reference;
      return entry;
    }),
  };

  if (rule !== null) {
    sale.discount = { key: rule.key };
    if (idLast4) sale.discount.id_ref_last4 = idLast4;
  }

  return sale;
}

/** A fresh id for a sale. crypto.randomUUID needs a secure context; the fallback builds a version 4 UUID from random bytes. */
export function newSaleId(random = globalThis.crypto) {
  if (typeof random.randomUUID === 'function') return random.randomUUID();
  const bytes = random.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
