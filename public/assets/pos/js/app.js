// The POS app, Batch 7.1 (online). One page, screens chosen by the URL hash:
//   #terminal  #shift  #sell  #receipts  #close
//
// This file wires things together and holds the app's state; it holds no pricing or tender rules (those are the pure
// modules next to it) and makes no server call except through api.js, and no sale is sent except through outbox.js.
// Everything is local: no request goes to another host, no script or font is loaded from elsewhere.

import { createApi, ApiError } from './api.js';
import { createOutbox } from './outbox.js';
import { createSnapshotStore } from './snapshot.js';
import { createStorage } from './storage.js';
import { createStatusBar } from './status.js';
import { emptyTicket } from './ticket.js';
import { confirmDialog } from './dialog.js';
import { openSignIn } from './signin.js';
import { openReceiptDialog } from './receipt-dialog.js';
import { receiptData } from './receiptdata.js';
import { createCheckout } from './checkout.js';
import { createTerminalScreen } from './terminal.js';
import { createShiftScreen } from './shift.js';
import { createSellScreen } from './sell.js';
import { createReceiptsScreen } from './receipts.js';
import { createCloseScreen } from './close.js';
import { $, $$ } from './dom.js';

const SCREENS = ['terminal', 'shift', 'sell', 'receipts', 'close'];

function main() {
  const config = JSON.parse(document.getElementById('pos-config').textContent);

  const state = {
    user: { id: config.user.id, name: config.user.name },
    terminals: [],
    terminal: null,
    shift: null,
    shiftIsMine: false,
    nextReceipt: null,
    ticket: emptyTicket(),
    discount: { rule: null, idLast4: '' },
    pending: null,
    online: navigator.onLine,
  };

  const storage = createStorage(config.posBase);
  const saleCache = new Map();
  const live = document.getElementById('pos-live');
  let networkReachable = true;
  let status = null;
  let current = null;

  // ---------------------------------------------------------------- services

  const api = createApi({
    base: config.apiBase,
    csrf: config.csrf,
    requestSignIn: (instance) => openSignIn(instance),
    onSession: ({ user }) => {
      // Someone signed in again after a timeout. The ticket is untouched; the shift may now belong to another person.
      state.user = { id: user.id, name: user.full_name };
      state.shiftIsMine = state.shift !== null && state.shift.cashier_user_id === state.user.id;
      status.update();
      status.renderTabs(current);
    },
    onNetwork: (reachable) => {
      networkReachable = reachable;
      syncOnline();
    },
  });
  const outbox = createOutbox(api);
  const snapshot = createSnapshotStore(api);

  function syncOnline() {
    state.online = navigator.onLine && networkReachable;
    status?.update();
  }

  // ---------------------------------------------------------------- shared helpers for the screens

  const ctx = {
    config, state, api, outbox, snapshot, storage,

    announce(message) {
      live.textContent = '';
      // A changed text is what a screen reader announces; clearing first makes a repeat announce again.
      setTimeout(() => { live.textContent = message; }, 20);
    },

    errorMessage(error) {
      if (!(error instanceof ApiError)) return 'Something went wrong. Please try again.';
      const details = [...new Set(Object.values(error.fields ?? {}).filter((text) => typeof text === 'string'))];
      return details.length > 0 && !details.includes(error.message) ? `${error.message} ${details.join(' ')}` : error.message;
    },

    navigate(screen) {
      if (location.hash === `#${screen}`) route();
      else location.hash = screen;
    },

    async refreshShift() {
      const data = await api.currentShift(state.terminal.id);
      applyShift(data.shift, data.next_receipt_number);
    },

    async openShift(openingCash) {
      const data = await api.openShift(state.terminal.id, openingCash);
      // A fresh shift starts from fresh prices and stock.
      await snapshot.load(state.terminal.id).catch(() => {});
      applyShift(data.shift, data.next_receipt_number);
      ctx.navigate('sell');
    },

    async closeShift(countedCash) {
      const data = await api.closeShift(state.shift.id, countedCash);
      applyShift(null, null);
      state.ticket = emptyTicket();
      state.discount = { rule: null, idLast4: '' };
      state.pending = null;
      return data.shift ?? data;
    },

    async chooseTerminal(terminal) {
      if (state.terminal !== null && state.terminal.id !== terminal.id && state.ticket.lines.length > 0) {
        const yes = await confirmDialog({
          title: 'Change terminal?', message: 'The ticket in progress will be cleared, because prices and stock belong to the terminal.',
          confirmLabel: 'Change terminal', danger: true,
        });
        if (!yes) return;
        state.ticket = emptyTicket();
        state.discount = { rule: null, idLast4: '' };
        state.pending = null;
      }
      const data = await snapshot.load(terminal.id);
      state.terminal = { ...terminal, ...data.terminal };
      storage.rememberTerminal(terminal.id);
      await ctx.refreshShift();
      ctx.navigate(state.shift !== null && state.shiftIsMine ? 'sell' : 'shift');
    },

    /** The sales made on this shift in this tab, read from the server (cached): [{record, sale}]. */
    async loadShiftSales() {
      const records = storage.sales(state.shift.id);
      const sales = await Promise.all(records.map(async (record) => ({ record, sale: await ctx.getSale(record.id) })));
      return sales;
    },

    async getSale(id) {
      if (!saleCache.has(id)) saleCache.set(id, await api.getSale(id));
      return saleCache.get(id);
    },

    /** Shows the receipt of a saved sale: the server's figures, never the preview. */
    async showReceipt(saleId, { newSale, cash = null, fallback = null }) {
      let sale;
      try {
        sale = await ctx.getSale(saleId);
      } catch (error) {
        // The sale is saved; only its details could not be read. Say so, and point to Receipts.
        await confirmDialog({
          title: newSale ? 'Sale saved' : 'Receipt not available',
          message: `${fallback?.receipt_number ? `Receipt ${fallback.receipt_number} is saved. ` : ''}Its details could not be loaded (${ctx.errorMessage(error)}). Open Receipts to print it once the connection is back.`,
          confirmLabel: 'OK', cancelLabel: 'Close',
        });
        return;
      }

      const record = storage.sales(state.shift?.id ?? '').find((entry) => entry.id === saleId);
      const known = cash ?? (record ? { cashTendered: record.tendered, change: record.change } : null);
      const data = receiptData({
        sale,
        terminal: state.terminal,
        organization: config.organization,
        vatRatePercent: snapshot.get()?.vat_rate_percent ?? 12,
        cashierName: sale.cashier_user_id === state.user.id ? state.user.name : '',
        cash: known,
      });
      const review = sale.status === 'needs_review' ? (sale.review_reason ?? 'Stock on record did not cover it.') : null;

      await openReceiptDialog(ctx, { data, review, newSale });
      if (newSale) sell.focusSearch();
    },
  };

  const checkout = createCheckout({ ...ctx, status: { update: () => status.update() }, refreshShift: () => ctx.refreshShift(), showReceipt: (...args) => ctx.showReceipt(...args) });
  ctx.pay = checkout.pay;

  function applyShift(shift, nextReceipt) {
    state.shift = shift;
    state.shiftIsMine = shift !== null && shift.cashier_user_id === state.user.id;
    state.nextReceipt = shift === null ? null : nextReceipt;
    status.update();
    status.renderTabs(current);
  }

  // ---------------------------------------------------------------- screens and routing

  const sell = createSellScreen(ctx);
  ctx.refreshTicket = (...args) => sell.refreshTicket(...args);
  const screens = {
    terminal: createTerminalScreen(ctx),
    shift: createShiftScreen(ctx),
    sell,
    receipts: createReceiptsScreen(ctx),
    close: createCloseScreen(ctx),
  };

  /** Where the person may be, given what is true: a terminal first, then an open shift of their own for selling. */
  function allowed(wanted) {
    if (state.terminal === null) return 'terminal';
    if (['sell', 'receipts'].includes(wanted) && !(state.shift !== null && state.shiftIsMine)) return 'shift';
    if (wanted === 'close' && state.shift === null) return 'shift';
    return wanted;
  }

  function route() {
    const asked = location.hash.slice(1);
    const wanted = SCREENS.includes(asked) ? asked : (state.terminal === null ? 'terminal' : state.shift !== null && state.shiftIsMine ? 'sell' : 'shift');
    const screen = allowed(wanted);
    if (screen !== asked) {
      history.replaceState(null, '', `#${screen}`);
    }

    if (current !== null && current !== screen) screens[current].hide();
    for (const section of $$('[data-screen]')) section.hidden = section.dataset.screen !== screen;
    current = screen;
    status.renderTabs(screen);
    status.update();

    const shown = screens[screen].show();
    Promise.resolve(shown).then(() => {
      if (screen === 'sell') return;
      // Screens with a keypad put the focus in it; the others get the heading, so a screen reader starts at the top.
      if (!document.activeElement || document.activeElement === document.body || !$(`#screen-${screen}`).contains(document.activeElement)) {
        $(`#screen-${screen} h1`)?.focus({ preventScroll: true });
      }
    });
  }

  // ---------------------------------------------------------------- start

  status = createStatusBar({ config, state, outbox });

  window.addEventListener('hashchange', route);
  window.addEventListener('online', syncOnline);
  window.addEventListener('offline', syncOnline);

  // Sign out: keep the form's CSRF field current, and do not throw a ticket away without asking.
  const signOut = $('.pos-status__signout');
  signOut.addEventListener('submit', async (event) => {
    $('[data-csrf-field]', signOut).value = api.csrfToken();
    if (state.ticket.lines.length === 0 || signOut.dataset.confirmed === 'yes') return;
    event.preventDefault();
    const yes = await confirmDialog({ title: 'Sign out?', message: 'The ticket in progress will be lost.', confirmLabel: 'Sign out', danger: true });
    if (yes) {
      signOut.dataset.confirmed = 'yes';
      signOut.requestSubmit();
    }
  });

  status.start();
  start();

  async function start() {
    // A remembered terminal on this device skips the choice, unless the person asked for the terminal list.
    if (location.hash !== '#terminal') {
      const remembered = storage.lastTerminal();
      if (remembered !== null) {
        try {
          const terminals = await api.terminals();
          const terminal = terminals.find((candidate) => candidate.id === remembered);
          if (terminal !== undefined) {
            state.terminals = terminals;
            await ctx.chooseTerminal(terminal);
            return;
          }
        } catch {
          // Fall through to the terminal screen, which shows the problem and a Try again button.
        }
      }
    }
    route();
  }
}

main();
