// Screen: the shift. Open it with the opening cash, see a short summary while it is open, or read why it cannot be used.

import { h, $, clear, append } from './dom.js';
import { createKeypad } from './keypad.js';
import { parseMoney, toApiMoney, formatPeso } from './money.js';
import { formatDateTime } from './datetime.js';
import { needsConnection } from './guards.js';

export function createShiftScreen(ctx) {
  const root = $('#screen-shift');

  async function show() {
    const { state } = ctx;
    if (state.shift === null) drawOpen();
    else if (state.shiftIsMine) await drawSummary();
    else drawOther();
  }

  function heading(text) {
    return h('h1', { id: 'title-shift', tabindex: '-1' }, text);
  }

  function terminalLine() {
    const { terminal } = ctx.state;
    return h('p', { class: 'pos-muted' }, `Terminal ${terminal.code}, ${terminal.branch_name}`);
  }

  function drawOpen(error = null) {
    const status = h('div', { class: 'pos-notice pos-notice--error', role: 'alert', hidden: error === null }, error ?? '');
    const keypad = createKeypad({ label: 'Opening cash in pesos', id: 'opening-cash' });
    const button = h('button', { class: 'pos-button pos-button--primary pos-button--large', type: 'submit' }, 'Open shift');

    const form = h('form', { class: 'pos-card pos-form', novalidate: true, onSubmit: async (event) => {
      event.preventDefault();
      const cash = parseMoney(keypad.value() === '' ? '0' : keypad.value());
      if (cash === null) {
        status.hidden = false;
        status.textContent = 'Enter the opening cash, like 2000.00.';
        keypad.input.focus();
        return;
      }
      button.disabled = true;
      try {
        await ctx.openShift(toApiMoney(cash));
      } catch (failure) {
        button.disabled = false;
        // Someone else opened a shift on this terminal a moment ago: show what is true now.
        if (failure.status === 409) {
          await ctx.refreshShift().catch(() => {});
          await show();
          return;
        }
        status.hidden = false;
        status.textContent = failure.fields?.opening_cash ?? ctx.errorMessage(failure);
      }
    } },
    h('label', { for: 'opening-cash', class: 'pos-label' }, 'Opening cash in the drawer'),
    keypad.element,
    status,
    button);

    append(clear(root), [
      heading('Open shift'), terminalLine(),
      ctx.state.online ? null : h('div', { class: 'pos-notice pos-notice--warn', role: 'status' }, needsConnection('Opening a shift')),
      h('p', {}, 'Count the cash in the drawer and enter it to start selling.'), form]);
    keypad.input.focus();
  }

  async function drawSummary() {
    const { state } = ctx;
    const summary = h('dl', { class: 'pos-summary', 'aria-live': 'polite' },
      h('div', {}, h('dt', {}, 'Opened at'), h('dd', {}, formatDateTime(state.shift.opened_at))),
      h('div', {}, h('dt', {}, 'Opening cash'), h('dd', {}, formatPeso(parseMoney(String(state.shift.opening_cash)) ?? 0))),
      h('div', {}, h('dt', {}, 'Sales so far'), h('dd', { 'data-summary': 'sales' }, 'Loading\u2026')),
      h('div', {}, h('dt', {}, 'Cash sales'), h('dd', { 'data-summary': 'cash' }, 'Loading\u2026')));

    append(clear(root), [
      heading('Shift'),
      terminalLine(),
      h('div', { class: 'pos-card' }, summary,
        h('p', { class: 'pos-muted' }, 'Counts the sales rung up on this terminal since the shift was opened, including any not sent to the server yet.')),
      h('div', { class: 'pos-actions' },
        h('a', { class: 'pos-button pos-button--primary pos-button--large', href: '#sell' }, 'Start selling'),
        h('a', { class: 'pos-button', href: '#close' }, 'Close shift')),
    ]);

    try {
      // From this device: sales already sent and sales still waiting, so the figures are right offline too.
      const sales = await ctx.outbox.shiftSales(state.shift.id);
      const salesCell = $('[data-summary="sales"]', root);
      const cashCell = $('[data-summary="cash"]', root);
      if (salesCell === null || cashCell === null) return; // the person has already moved to another screen
      const total = sales.reduce((sum, sale) => sum + (parseMoney(sale.total) ?? 0), 0);
      const cash = sales.reduce((sum, sale) => sum + (sale.cash_total ?? 0), 0);
      const waiting = sales.filter((sale) => sale.state !== 'synced').length;
      salesCell.textContent = `${sales.length} ${sales.length === 1 ? 'sale' : 'sales'}, ${formatPeso(total)}${waiting > 0 ? ` (${waiting} not sent yet)` : ''}`;
      cashCell.textContent = formatPeso(cash);
    } catch {
      const salesCell = $('[data-summary="sales"]', root);
      const cashCell = $('[data-summary="cash"]', root);
      if (salesCell !== null) salesCell.textContent = 'Could not load';
      if (cashCell !== null) cashCell.textContent = 'Could not load';
    }
  }

  function drawOther() {
    append(clear(root), [
      heading('Another cashier has a shift open'),
      terminalLine(),
      h('div', { class: 'pos-notice', role: 'status' },
        `This terminal has a shift open since ${formatDateTime(ctx.state.shift.opened_at)} that belongs to someone else. `,
        'Sales go on the shift of the person who opened it. Ask that cashier to close it, or choose another terminal.'),
      h('div', { class: 'pos-actions' },
        h('a', { class: 'pos-button pos-button--primary', href: '#terminal' }, 'Choose another terminal'),
        h('a', { class: 'pos-button', href: '#close' }, 'Close this shift')),
    ]);
  }

  return { show, hide() {} };
}
