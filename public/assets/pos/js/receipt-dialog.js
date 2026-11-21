// Dialog: the receipt, with Print and New sale (or Close, for a reprint).

import { h, append } from './dom.js';
import { openModal } from './dialog.js';
import { renderReceipt } from './receipt.js';

/**
 * @param {object} ctx
 * @param {object} options
 * @param {object} options.data the receipt fields from receiptData()
 * @param {string|null} [options.review] a note for the cashier when the server kept the sale for review (never printed)
 * @param {boolean} [options.newSale] true right after a sale: the main button starts the next one
 */
export function openReceiptDialog(ctx, { data, review = null, newSale = false }) {
  const modal = openModal({ title: newSale ? 'Sale complete' : 'Receipt', className: 'pos-dialog--receipt' });
  const receipt = renderReceipt(ctx.config.receiptTemplate, data);

  const print = () => {
    const sheet = document.getElementById('print-sheet');
    sheet.replaceChildren(receipt.cloneNode(true));
    const clean = () => sheet.replaceChildren();
    window.addEventListener('afterprint', clean, { once: true });
    window.print();
  };

  append(modal.body, [
    review === null ? null : h('div', { class: 'pos-notice pos-notice--warn', role: 'status' },
      'This sale is saved and was flagged for a manager to review. ', review),
    h('div', { class: 'pos-receipt-frame' }, receipt),
    h('div', { class: 'pos-actions' },
      h('button', { class: 'pos-button', type: 'button', onClick: print }, 'Print'),
      h('button', { class: 'pos-button pos-button--primary pos-button--large', type: 'button', onClick: () => modal.close(true) }, newSale ? 'New sale' : 'Close')),
  ]);
  modal.body.querySelector('.pos-button--primary').focus();

  return modal.closed;
}
