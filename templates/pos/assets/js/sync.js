// Screen: Sync (#sync). Lists the sales that have not reached the server yet, with Sync now and a Retry for each.
//
// A sale shown here is safe on this device. "Needs attention" means the server refused it (for example a receipt number
// it already has); the reason is shown and the cashier is told to ask a manager. There is deliberately no way to delete
// a sale that was not sent: the only ways out of this list are the server accepting it, or a manager fixing the cause
// and the sale then being sent with Retry.

import { h, $, clear, append } from './dom.js';
import { formatPeso, parseMoney } from './money.js';
import { formatTime, formatDate } from './datetime.js';

const STATE_LABEL = { queued: 'Waiting', sending: 'Sending\u2026', needs_attention: 'Needs attention' };
const STATE_CLASS = { queued: 'pos-pill--warn', sending: 'pos-pill--warn', needs_attention: 'pos-pill--attention' };

export function createSyncScreen(ctx) {
  const root = $('#screen-sync');
  let unsubscribe = null;
  let refs = null;

  function build() {
    refs = {
      summary: h('p', { class: 'pos-sync__summary', role: 'status', 'aria-live': 'polite' }),
      notes: h('div', { class: 'pos-sync__notes' }),
      button: h('button', { class: 'pos-button pos-button--primary pos-button--large', type: 'button', onClick: syncNow }, 'Sync now'),
      list: h('ul', { class: 'pos-sync__list' }),
    };
    append(clear(root), [
      h('h1', { id: 'title-sync', tabindex: '-1' }, 'Sync'),
      h('p', { class: 'pos-muted' }, 'Sales that are saved on this device and have not been accepted by the server yet.'),
      h('div', { class: 'pos-sync__bar' }, refs.summary, refs.button),
      refs.notes,
      refs.list,
    ]);
  }

  async function syncNow() {
    refs.button.disabled = true;
    try {
      await ctx.outbox.sync({ force: true });
    } finally {
      await draw();
    }
  }

  function row(record) {
    const needsAttention = record.state === 'needs_attention';
    const total = formatPeso(parseMoney(record.total) ?? 0);

    return h('li', { class: `pos-sync-row pos-card${needsAttention ? ' pos-sync-row--attention' : ''}`, 'data-sale': record.id },
      h('div', { class: 'pos-sync-row__main' },
        h('strong', { class: 'pos-sync-row__no' }, record.receipt_number),
        h('span', { class: 'pos-muted' }, `${formatDate(record.sold_at)}, ${formatTime(record.sold_at)}`),
        h('strong', { class: 'pos-sync-row__total' }, total),
        h('span', { class: `pos-pill ${STATE_CLASS[record.state] ?? 'pos-pill--quiet'}` }, STATE_LABEL[record.state] ?? record.state),
        h('span', { class: 'pos-muted' }, `${record.attempts} ${record.attempts === 1 ? 'attempt' : 'attempts'}`),
        h('button', { class: 'pos-button', type: 'button', 'aria-label': `Retry sale ${record.receipt_number}`, onClick: () => retry(record.id) }, 'Retry')),
      record.last_error === null ? null : h('p', { class: needsAttention ? 'pos-field-error' : 'pos-muted', 'data-problem': record.id }, `${needsAttention ? 'The server said: ' : 'Last problem: '}${record.last_error}`),
      needsAttention ? h('p', { class: 'pos-sync-row__help' }, 'Ask a manager to look at this sale. It stays safe on this device and cannot be deleted here. Once the cause is fixed, press Retry.') : null);
  }

  async function retry(id) {
    await ctx.outbox.retry(id);
    await draw();
  }

  async function draw() {
    if (refs === null) return;
    const records = await ctx.outbox.list();
    const state = ctx.outbox.state();
    const attention = records.filter((record) => record.state === 'needs_attention').length;

    refs.summary.textContent = records.length === 0
      ? 'Nothing is waiting. Every sale has been sent.'
      : `${records.length} ${records.length === 1 ? 'sale' : 'sales'} waiting${attention > 0 ? `, ${attention} needing attention` : ''}.`;
    refs.button.disabled = state.running;
    refs.button.textContent = state.running ? 'Syncing\u2026' : 'Sync now';

    const notes = [];
    if (!ctx.state.online) notes.push(h('div', { class: 'pos-notice pos-notice--warn' }, 'There is no connection. The sales below are safe on this device and are sent by themselves when the connection is back.'));
    if (state.paused) notes.push(h('div', { class: 'pos-notice pos-notice--warn' }, 'Sending is paused because you were signed out. Press Sync now and sign in to carry on.'));
    append(clear(refs.notes), notes);

    append(clear(refs.list), records.map(row));
  }

  return {
    async show() {
      build();
      unsubscribe = ctx.outbox.subscribe(() => { draw(); });
      await draw();
    },
    hide() {
      unsubscribe?.();
      unsubscribe = null;
      refs = null;
    },
    redraw: draw,
  };
}
