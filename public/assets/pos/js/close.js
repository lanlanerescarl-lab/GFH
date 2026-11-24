// Screen: close the shift. A counted-cash keypad, a confirmation, then the result: expected cash, counted cash and the variance.
// Closing needs the connection, and it is refused while any sale of this shift is still waiting to be sent (queued, being
// sent, or needing attention): the message links to the sync screen. The check runs when the screen opens and again when
// the button is pressed, because a sale can be made or fail in between.

import { h, $, clear, append } from './dom.js';
import { createKeypad } from './keypad.js';
import { parseMoney, toApiMoney, formatPeso } from './money.js';
import { confirmDialog } from './dialog.js';
import { needsConnection } from './guards.js';

export function createCloseScreen(ctx) {
  const root = $('#screen-close');

  async function show() {
    if (ctx.state.shift === null) {
      ctx.navigate('shift');
      return;
    }
    const guard = await ctx.guards.closeShift();
    if (!guard.allowed) drawRefused(guard);
    else drawCount();
  }

  /** The refusal, with the way out: the sync screen. */
  function refusal(guard) {
    return h('div', { class: 'pos-notice pos-notice--warn', role: 'alert', 'data-refusal': 'close' },
      `${guard.message} `, h('a', { href: '#sync' }, 'Open the sync screen'), '.');
  }

  function drawRefused(guard) {
    append(clear(root), [
      heading('Close shift'),
      h('p', { class: 'pos-muted' }, `Terminal ${ctx.state.terminal.code}, ${ctx.state.terminal.branch_name}`),
      refusal(guard),
      h('div', { class: 'pos-actions' }, h('a', { class: 'pos-button pos-button--primary pos-button--large', href: '#sync' }, 'Go to the sync screen')),
    ]);
  }

  function heading(text) {
    return h('h1', { id: 'title-close', tabindex: '-1' }, text);
  }

  function drawCount(error = null) {
    const { state } = ctx;
    const status = h('div', { class: 'pos-notice pos-notice--error', role: 'alert', hidden: error === null }, error ?? '');
    const keypad = createKeypad({ label: 'Counted cash in pesos', id: 'counted-cash' });
    const button = h('button', { class: 'pos-button pos-button--primary pos-button--large', type: 'submit' }, 'Close shift');
    const offline = ctx.state.online ? null : h('div', { class: 'pos-notice pos-notice--warn', role: 'status' }, needsConnection('Closing the shift'));

    const form = h('form', { class: 'pos-card pos-form', novalidate: true, onSubmit: async (event) => {
      event.preventDefault();
      const counted = parseMoney(keypad.value());
      if (counted === null) {
        status.hidden = false;
        status.textContent = 'Enter the counted cash, like 5230.50.';
        keypad.input.focus();
        return;
      }

      const lines = state.ticket.lines.length;
      const confirmed = await confirmDialog({
        title: 'Close this shift?',
        message: `You counted ${formatPeso(counted)}. After closing, nothing more can be sold on this shift.`
          + (lines > 0 ? ' The ticket in progress will be cleared.' : ''),
        confirmLabel: 'Close shift',
        cancelLabel: 'Keep selling',
        danger: true,
      });
      if (!confirmed) return;

      button.disabled = true;
      try {
        const closed = await ctx.closeShift(toApiMoney(counted));
        drawResult(closed);
      } catch (failure) {
        button.disabled = false;
        if (failure.guard) {
          drawRefused(failure.guard);
          return;
        }
        status.hidden = false;
        status.textContent = failure.fields?.counted_cash ?? failure.fields?.shift ?? ctx.errorMessage(failure);
      }
    } },
    h('label', { for: 'counted-cash', class: 'pos-label' }, 'Cash counted in the drawer'),
    keypad.element,
    status,
    button);

    append(clear(root), [
      heading('Close shift'),
      h('p', { class: 'pos-muted' }, `Terminal ${state.terminal.code}, ${state.terminal.branch_name}`),
      offline,
      h('p', {}, 'Count all the cash in the drawer, including the opening cash, and enter the total.'),
      form,
    ]);
    keypad.input.focus();
  }

  function drawResult(closed) {
    const expected = parseMoney(String(closed.expected_cash)) ?? 0;
    const counted = parseMoney(String(closed.counted_cash)) ?? 0;
    const variance = parseMoney(String(closed.variance)) ?? 0;
    const verdict = variance === 0 ? 'The drawer matches.' : variance > 0 ? `Over by ${formatPeso(variance)}.` : `Short by ${formatPeso(-variance)}.`;

    append(clear(root), [
      heading('Shift closed'),
      h('div', { class: 'pos-card' },
        h('dl', { class: 'pos-summary' },
          h('div', {}, h('dt', {}, 'Expected cash'), h('dd', {}, formatPeso(expected))),
          h('div', {}, h('dt', {}, 'Counted cash'), h('dd', {}, formatPeso(counted))),
          h('div', {}, h('dt', {}, 'Variance'), h('dd', { class: variance === 0 ? '' : 'pos-text-warn' }, formatPeso(variance)))),
        h('p', { class: 'pos-verdict', role: 'status' }, verdict)),
      h('div', { class: 'pos-actions' },
        h('a', { class: 'pos-button pos-button--primary pos-button--large', href: '#shift' }, 'Back to the shift screen')),
    ]);
    $('h1', root).focus();
  }

  return { show, hide() {} };
}
