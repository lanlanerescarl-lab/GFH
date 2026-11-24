// The receipt of a sale that has not reached the server yet. The Pay flow used to read the server's sale back
// (GET /pos/sales/{id}) and print that. A sale saved offline has no server copy, so the receipt is built here from the
// priced ticket (pricing.js, which mirrors the server's PricingService and is checked against the same fixtures) and
// handed to receiptData() in the shape the server would have returned. The server still recomputes everything when the
// sale arrives; if its total ever differed, its figures are the ones on record.
//
// Pure functions, no DOM, no network.

import { toApiMoney, toApiQuantity } from './money.js';
import { receiptData } from './receiptdata.js';

/**
 * The sale as GET /pos/sales/{id} would show it, from what the cashier rang up.
 *
 * @param {object} input
 * @param {object} input.sale the payload built by sale.js (id, receipt_number, sold_at, payments, ...)
 * @param {object} input.priced the result of pricing.priceSale()
 * @param {Map<string, object>} input.productsByVariant the snapshot's products by variant id
 * @param {{label: string}|null} [input.rule] the discount rule chosen for the sale
 * @param {string|null} [input.idLast4]
 */
export function serverShapedSale({ sale, priced, productsByVariant, rule = null, idLast4 = null }) {
  return {
    id: sale.id,
    receipt_number: sale.receipt_number,
    sold_at: sale.sold_at,
    status: 'queued',
    lines: priced.lines.map((line) => ({
      name: productsByVariant.get(line.variantId)?.name ?? '',
      qty: toApiQuantity(line.quantityMilli),
      unit_price: toApiMoney(line.unitPrice),
      line_total: toApiMoney(line.lineTotal),
    })),
    subtotal: toApiMoney(priced.subtotal),
    discount_total: toApiMoney(priced.discountTotal),
    vat_amount: toApiMoney(priced.vatAmount),
    total: toApiMoney(priced.total),
    discounts: rule === null ? [] : [{ label: rule.label, id_ref_last4: idLast4 || null }],
    payments: sale.payments,
  };
}

/**
 * A function that builds the receipt fields for a receipt number; the outbox calls it inside the transaction that
 * takes the number, so the stored copy carries the number it was printed with.
 */
export function receiptBuilder({ sale, priced, productsByVariant, rule, idLast4, terminal, organization, vatRatePercent, cashierName, cash }) {
  return (receiptNumber) => receiptData({
    sale: serverShapedSale({ sale: { ...sale, receipt_number: receiptNumber }, priced, productsByVariant, rule, idLast4 }),
    terminal,
    organization,
    vatRatePercent,
    cashierName,
    cash,
  });
}
