// From the Pay button to a saved sale. The app's one place that turns a priced ticket and a settlement into a sale,
// sends it through the outbox, and clears the ticket once the server has it.
//
// A sale keeps its id (and receipt number) until the ticket or the payments change, so pressing Retry after a failure
// sends exactly the same sale. If the first attempt did reach the server, the server answers "duplicate" instead of
// recording it twice.

import { buildSale, newSaleId } from './sale.js';
import { emptyTicket } from './ticket.js';
import { nextReceiptNumber } from './receiptno.js';
import { toApiMoney } from './money.js';
import { openPayment } from './payment.js';

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

  function sale(priced, settlement, receiptNumber, existing = null) {
    const { state } = ctx;
    return buildSale({
      id: existing?.id ?? newSaleId(),
      receiptNumber,
      terminalId: state.terminal.id,
      shiftId: state.shift.id,
      soldAt: existing?.sold_at ?? new Date().toISOString(),
      lines: priced.lines,
      payments: settlement.payments,
      rule: state.discount.rule,
      idLast4: state.discount.idLast4 || null,
    });
  }

  /**
   * Saves the sale. Resolves {result, cash} on success; throws the API's error otherwise, leaving the ticket as it was.
   * @returns {Promise<{result: object, cash: {cashTendered: number, change: number}}>}
   */
  async function completeSale(priced, settlement) {
    const { state } = ctx;
    if (state.nextReceipt === null) await ctx.refreshShift();

    const signature = signatureOf(priced, settlement);
    if (state.pending === null || state.pending.signature !== signature) {
      state.pending = { signature, sale: sale(priced, settlement, state.nextReceipt) };
    }

    let result;
    try {
      result = await ctx.outbox.submit(state.pending.sale);
    } catch (error) {
      // Another till or tab used this receipt number: ask the server for the next free one and send the same sale once more.
      if (error.status === 409 && error.fields?.receipt_number) {
        await ctx.refreshShift();
        state.pending = { signature, sale: sale(priced, settlement, state.nextReceipt, state.pending.sale) };
        result = await ctx.outbox.submit(state.pending.sale);
      } else {
        throw error;
      }
    }

    // The server's total is the one that counts. A difference means the preview and the server disagree: say so in the console.
    if (result.total !== toApiMoney(priced.total)) {
      console.warn('POS price difference: the server worked out a different total than the preview.', { preview: toApiMoney(priced.total), server: result.total, sale: result.sale_id });
    }

    const cash = { cashTendered: settlement.cashTendered, change: settlement.change };
    state.nextReceipt = nextReceiptNumber(result.receipt_number) ?? state.nextReceipt;
    ctx.storage.addSale(state.shift.id, { id: result.sale_id, tendered: cash.cashTendered, change: cash.change });
    state.ticket = emptyTicket();
    state.discount = { rule: null, idLast4: '' };
    state.pending = null;

    return { result, cash };
  }

  /** Opens the payment dialog; when the sale is saved, shows its receipt. Resolves true if a sale was made. */
  async function pay(priced) {
    const done = await openPayment({ ...ctx, completeSale }, priced);
    if (!done) return false;

    ctx.status.update();
    await ctx.showReceipt(done.result.sale_id, {
      newSale: true,
      cash: done.cash,
      fallback: done.result,
    });
    return true;
  }

  return { pay, completeSale };
}
