// Dialog: take payment. Payment methods come from the snapshot, split payments are allowed, cash shows quick amounts
// and the change due in large type, and Complete sale stays off until the payments cover the total.
// The arithmetic is settle() in tender.js; this file only draws it.

import { h, append, clear, uid } from './dom.js';
import { openModal } from './dialog.js';
import { parseMoney, toApiMoney, formatPeso } from './money.js';
import { settle, quickAmounts, methodLabel, needsReference } from './tender.js';

export function openPayment(ctx, priced) {
  const modal = openModal({ title: 'Payment', className: 'pos-dialog--wide', dismissible: true });
  const methods = ctx.snapshot.get()?.payment_methods ?? ['cash'];
  const total = priced.total;
  let nextKey = 0;
  const entry = (method, text = '') => ({ key: (nextKey += 1), method, text, reference: '' });
  let entries = [entry(methods.includes('cash') ? 'cash' : methods[0])];
  let sending = false;
  let failedToReach = false;
  let serverFields = {};

  const dueLine = h('p', { class: 'pos-due' }, h('span', { class: 'pos-due__label' }, 'Total due'), ' ', h('strong', { class: 'pos-due__amount' }, formatPeso(total)));
  const list = h('div', { class: 'pos-payments' });
  const adders = h('div', { class: 'pos-methods', role: 'group', 'aria-label': 'Add a payment method' });
  const summary = h('div', { class: 'pos-settle', 'aria-live': 'polite', 'aria-atomic': 'true' });
  const banner = h('div', { class: 'pos-notice pos-notice--error', role: 'alert', hidden: true });
  const complete = h('button', { class: 'pos-button pos-button--pay pos-button--large', type: 'button' }, 'Complete sale');
  const cancel = h('button', { class: 'pos-button', type: 'button', onClick: () => modal.close(null) }, 'Back to ticket');

  const amountOf = (item) => parseMoney(item.text) ?? 0;
  const current = () => settle(total, entries.map((item) => ({ method: item.method, amount: amountOf(item), reference: item.reference })));

  const quickRows = new Map();

  function drawEntries() {
    clear(list);
    quickRows.clear();
    for (const item of entries) {
      const amountId = uid('pay-amount');
      const refId = uid('pay-ref');
      const problemId = uid('pay-problem');
      const amount = h('input', {
        id: amountId, class: 'pos-input pos-input--amount', type: 'text', inputmode: 'decimal', autocomplete: 'off', value: item.text, placeholder: '0.00',
        'aria-describedby': problemId,
      });
      amount.addEventListener('input', () => { item.text = amount.value.trim(); changed(); });
      const problem = h('p', { class: 'pos-field-error', id: problemId, role: 'alert', 'data-problem': item.key, hidden: true });

      const reference = needsReference(item.method)
        ? h('div', { class: 'pos-field' },
          h('label', { for: refId }, `${methodLabel(item.method)} reference number`),
          h('input', { id: refId, class: 'pos-input', type: 'text', autocomplete: 'off', maxlength: '80', value: item.reference, onInput: (event) => { item.reference = event.target.value; changed(); } }))
        : null;

      let quick = null;
      if (item.method === 'cash') {
        quick = h('div', { class: 'pos-quick', role: 'group', 'aria-label': 'Quick cash amounts' });
        quickRows.set(item.key, { quick, amount });
      }

      append(list, [h('section', { class: 'pos-payment pos-card', 'aria-label': `${methodLabel(item.method)} payment` },
        h('div', { class: 'pos-payment__head' },
          h('h3', {}, methodLabel(item.method)),
          entries.length > 1 ? h('button', { class: 'pos-button pos-button--quiet', type: 'button', 'aria-label': `Remove the ${methodLabel(item.method)} payment`, onClick: () => { entries = entries.filter((other) => other !== item); drawEntries(); changed(); } }, 'Remove') : null),
        h('div', { class: 'pos-field' },
          h('label', { for: amountId }, item.method === 'cash' ? 'Cash handed over' : 'Amount'),
          amount,
          problem),
        quick,
        reference)]);
    }
  }

  // Quick cash amounts are about what cash still has to cover after the other methods, so they follow those amounts.
  function drawQuick() {
    for (const [key, { quick, amount }] of quickRows) {
      const item = entries.find((candidate) => candidate.key === key);
      const forCash = Math.max(0, total - entries.filter((other) => other.method !== 'cash').reduce((sum, other) => sum + amountOf(other), 0));
      append(clear(quick), quickAmounts(forCash).map((value, position) => h('button', {
        class: 'pos-button pos-button--chip', type: 'button',
        onClick: () => { item.text = toApiMoney(value); amount.value = item.text; changed(); amount.focus(); },
      }, position === 0 ? `Exact ${formatPeso(value)}` : formatPeso(value))));
    }
  }

  function drawAdders() {
    append(clear(adders), [h('p', { class: 'pos-label' }, 'Add a payment method'), ...methods.map((method) => h('button', {
      class: 'pos-button pos-button--chip', type: 'button', onClick: () => addMethod(method),
    }, methodLabel(method)))]);
  }

  function addMethod(method) {
    const existingCash = method === 'cash' ? entries.find((item) => item.method === 'cash') : null;
    if (existingCash) {
      list.querySelector('input')?.focus();
      return;
    }
    const remaining = current().remaining;
    entries = [...entries, entry(method, remaining > 0 && method !== 'cash' ? toApiMoney(remaining) : '')];
    drawEntries();
    changed();
    list.lastElementChild?.querySelector('input')?.focus();
  }

  function changed() {
    // Any edit makes this a different payment, so a failed attempt is no longer the one to retry.
    failedToReach = false;
    serverFields = {};
    banner.hidden = true;
    refresh();
  }

  function refresh() {
    const result = current();

    // A message sits under its payment. An amount problem shows once something is typed; a missing reference shows
    // as soon as the amount is there; a refusal from the server (for example on a reference) shows too.
    for (const problem of list.querySelectorAll('[data-problem]')) {
      const index = entries.findIndex((candidate) => String(candidate.key) === problem.dataset.problem);
      const item = entries[index];
      const found = result.problems.find((p) => p.index === index && (p.field === 'reference' ? item.text !== '' : item.text !== ''));
      const message = found?.message ?? serverFields[`payments.${index}.reference`] ?? serverFields[`payments.${index}.amount`] ?? '';
      problem.textContent = message;
      problem.hidden = message === '';
    }
    drawQuick();

    clear(summary);
    append(summary, [
      h('p', { class: 'pos-settle__row' }, h('span', {}, 'Paid'), ' ', h('strong', {}, formatPeso(result.applied))),
      result.remaining > 0 ? h('p', { class: 'pos-settle__big pos-settle__big--short' }, h('span', {}, 'Still due'), ' ', h('strong', {}, formatPeso(result.remaining))) : null,
      result.change > 0 ? h('p', { class: 'pos-settle__big pos-settle__big--change' }, h('span', {}, 'Change due'), ' ', h('strong', {}, formatPeso(result.change))) : null,
      result.remaining === 0 && result.change === 0 && result.applied > 0 ? h('p', { class: 'pos-settle__row' }, h('span', {}, 'Exact amount'), ' ', h('strong', {}, 'No change')) : null,
    ]);

    complete.disabled = sending || !result.complete;
    complete.textContent = sending ? 'Sending\u2026' : failedToReach ? 'Retry' : 'Complete sale';
  }

  complete.addEventListener('click', async () => {
    const result = current();
    if (!result.complete || sending) return;
    sending = true;
    banner.hidden = true;
    refresh();
    try {
      const done = await ctx.completeSale(priced, result);
      modal.close(done);
    } catch (error) {
      sending = false;
      failedToReach = Boolean(error.network);
      serverFields = error.fields ?? {};
      banner.hidden = false;
      banner.textContent = error.network
        ? 'The sale has not been sent: the server cannot be reached. Your ticket is safe. Check the connection and press Retry.'
        : ctx.errorMessage(error);
      refresh();
      complete.focus();
    }
  });

  drawEntries();
  drawAdders();
  append(modal.body, [dueLine, list, adders, summary, banner, h('div', { class: 'pos-actions' }, cancel, complete)]);
  refresh();
  list.querySelector('input')?.focus();

  return modal.closed;
}
