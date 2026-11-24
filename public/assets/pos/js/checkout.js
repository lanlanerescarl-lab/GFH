// From the Pay button to a saved sale. The app's one place that turns a priced ticket and a settlement into a sale,
// saves it through the outbox, and clears the ticket once it is safe on this device.
//
// Batch 7.2: saving is local. The outbox writes the sale to the queue and gives it the next receipt number in one
// storage transaction, then returns at once; the sync loop sends it to the server in the background. The receipt is
// built here from the priced ticket (localsale.js), so it prints with or without a connection.
//
// A sale keeps its id until the ticket or the payments change, so pressing Retry after a failed save saves exactly the
// same sale: the outbox treats the same id as the same sale and never takes a second receipt number for it.

import { buildSale, newSaleId } from './sale.js';
import { emptyTicket } from './ticket.js';
import { nextReceiptNumber } from './receiptno.js';
import { toApiMoney } from './money.js';
import { openPayment } from './payment.js';
import { receiptBuilder } from './localsale.js';

export function createCheckout(ctx) {
  function signatureOf(priced, settlement) {
    const { rule, idLast4 } = ctx.state.discount;
    return JSON.stringify([
      ctx.state.shift.id,
      priced.lines.map((line) => [line.variantId, line.quantityMilli, line.unitPrice]),
      settlement.payments.map((payment) => [payment.method, payment.amount, payment.reference]),
      rule?.key ?? null,
      idLast4,
    ]);
  }

  /** The sale without a receipt number: the outbox gives it one when it is saved. */
  function draft(priced, settlement) {
    const { state } = ctx;
    return buildSale({
      id: newSaleId(),
      receiptNumber: null,
      terminalId: state.terminal.id,
      shiftId: state.shift.id,
      soldAt: new Date().toISOString(),
      lines: priced.lines,
      payments: settlement.payments,
      rule: state.discount.rule,
      idLast4: state.discount.idLast4 || null,
    });
  }

  /**
   * Saves the sale on this device. Resolves {result, cash, receipt} on success; throws otherwise, leaving the ticket as it was.
   * @returns {Promise<{result: object, cash: {cashTendered: number, change: number}, receipt: object}>}
   */
  async function completeSale(priced, settlement) {
    const { state } = ctx;

    const signature = signatureOf(priced, settlement);
    if (state.pending === null || state.pending.signature !== signature) {
      state.pending = { signature, sale: draft(priced, settlement) };
    }

    const cash = { cashTendered: settlement.cashTendered, change: settlement.change };
    const buildReceipt = receiptBuilder({
      sale: state.pending.sale,
      priced,
      productsByVariant: ctx.snapshot.productsByVariant(),
      rule: state.discount.rule,
      idLast4: state.discount.idLast4 || null,
      terminal: state.terminal,
      organization: ctx.config.organization,
      vatRatePercent: ctx.snapshot.get()?.vat_rate_percent ?? 12,
      cashierName: state.user.name,
      cash,
    });

    const result = await ctx.outbox.submit(state.pending.sale, { buildReceipt, cash });

    // The payments add up to the total the preview worked out; a difference means something is wrong in the preview.
    if (result.total !== toApiMoney(priced.total)) {
      console.warn('POS price difference: the saved total differs from the preview.', { preview: toApiMoney(priced.total), saved: result.total, sale: result.sale_id });
    }

    state.nextReceipt = nextReceiptNumber(result.receipt_number) ?? state.nextReceipt;
    state.ticket = emptyTicket();
    state.discount = { rule: null, idLast4: '' };
    state.pending = null;

    return { result, cash, receipt: buildReceipt(result.receipt_number) };
  }

  /** Opens the payment dialog; when the sale is saved, shows its receipt. Resolves true if a sale was made. */
  async function pay(priced) {
    const done = await openPayment({ ...ctx, completeSale }, priced);
    if (!done) return false;

    ctx.status.update();
    await ctx.showReceipt(done.receipt, { newSale: true, queued: !ctx.state.online });
    return true;
  }

  return { pay, completeSale };
}
