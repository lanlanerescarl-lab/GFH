// Screen: the shift's receipts, queued and sent together, newest first, with reprint. The list comes from this device
// (outbox.shiftSales): sales still waiting to be sent, and the compact records kept for sales the server accepted.
// A reprint is the receipt as it was printed the first time, so it works offline. Waiting sales are marked, and so are
// sales the server flagged for a manager to review.

import { h, $, clear, append } from './dom.js';
import { formatPeso, parseMoney } from './money.js';
import { formatTime } from './datetime.js';

const SHOWN = 50;

export function createReceiptsScreen(ctx) {
  const root = $('#screen-receipts');

  async function show() {
    const heading = h('h1', { id: 'title-receipts', tabindex: '-1' }, 'Receipts');
    const list = h('ul', { class: 'pos-receipts', 'aria-live': 'polite' });
    append(clear(root), [heading, h('p', { class: 'pos-muted' }, 'The sales rung up on this terminal during this shift, newest first. Sales not yet sent to the server are marked.'), list]);

    const sales = (await ctx.outbox.shiftSales(ctx.state.shift.id)).slice(0, SHOWN);
    if (sales.length === 0) {
      list.append(h('li', { class: 'pos-notice' }, 'No sales yet on this shift.'));
      return;
    }

    for (const sale of sales) {
      list.append(h('li', { class: 'pos-receipt-row pos-card', 'data-sale': sale.id },
        h('span', { class: 'pos-receipt-row__no' }, sale.receipt_number),
        h('span', { class: 'pos-muted' }, formatTime(sale.sold_at)),
        h('strong', { class: 'pos-receipt-row__total' }, formatPeso(parseMoney(sale.total) ?? 0)),
        sale.state === 'queued' || sale.state === 'sending' ? h('span', { class: 'pos-pill pos-pill--warn' }, 'Queued') : null,
        sale.state === 'needs_attention' ? h('a', { class: 'pos-pill pos-pill--attention', href: '#sync' }, 'Needs attention') : null,
        sale.needs_review ? h('span', { class: 'pos-pill pos-pill--warn' }, 'Needs review') : null,
        h('button', { class: 'pos-button', type: 'button', disabled: sale.receipt === null, onClick: () => ctx.showReceipt(sale.receipt, { newSale: false, review: sale.needs_review ? (sale.review_reason ?? 'Stock on record did not cover it.') : null }) }, 'Reprint')));
    }
  }

  return { show, hide() {} };
}
