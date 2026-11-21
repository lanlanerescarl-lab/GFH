// Screen: the shift's recent sales (receipt number, time, total) with reprint. The list is the sale ids kept in
// sessionStorage for this shift; each row is read from the server with GET /pos/sales/{id}.

import { h, $, clear, append } from './dom.js';
import { formatPeso, parseMoney } from './money.js';
import { formatTime } from './datetime.js';

const SHOWN = 20;

export function createReceiptsScreen(ctx) {
  const root = $('#screen-receipts');

  async function show() {
    const heading = h('h1', { id: 'title-receipts', tabindex: '-1' }, 'Receipts');
    const list = h('ul', { class: 'pos-receipts', 'aria-live': 'polite' });
    append(clear(root), [heading, h('p', { class: 'pos-muted' }, 'The sales rung up in this browser tab on this shift, newest first.'), list]);

    const records = ctx.storage.sales(ctx.state.shift.id).slice(0, SHOWN);
    if (records.length === 0) {
      list.append(h('li', { class: 'pos-notice' }, 'No sales yet on this shift.'));
      return;
    }

    for (const record of records) {
      const row = h('li', { class: 'pos-receipt-row pos-card' }, h('span', { class: 'pos-muted' }, 'Loading\u2026'));
      list.append(row);
      load(record, row);
    }
  }

  async function load(record, row) {
    try {
      const sale = await ctx.getSale(record.id);
      append(clear(row), [
        h('span', { class: 'pos-receipt-row__no' }, sale.receipt_number),
        h('span', { class: 'pos-muted' }, formatTime(sale.sold_at)),
        h('strong', { class: 'pos-receipt-row__total' }, formatPeso(parseMoney(sale.total) ?? 0)),
        sale.status === 'needs_review' ? h('span', { class: 'pos-pill pos-pill--warn' }, 'Needs review') : null,
        h('button', { class: 'pos-button', type: 'button', onClick: () => ctx.showReceipt(record.id, { newSale: false }) }, 'Reprint'),
      ]);
    } catch (error) {
      append(clear(row), [
        h('span', { class: 'pos-field-error' }, `Could not load this sale: ${ctx.errorMessage(error)}`),
        h('button', { class: 'pos-button', type: 'button', onClick: () => load(record, row) }, 'Try again'),
      ]);
    }
  }

  return { show, hide() {} };
}
