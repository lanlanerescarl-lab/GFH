// The POS app, Batch 7.2 (offline layer). One page, screens chosen by the URL hash:
//   #terminal  #shift  #sell  #receipts  #sync  #close
//
// This file wires things together and holds the app's state; it holds no pricing or tender rules (those are the pure
// modules next to it) and makes no server call except through api.js, and no sale is sent except through outbox.js.
// Everything is local: no request goes to another host, no script or font is loaded from elsewhere.
//
// What 7.2 adds: a local store (IndexedDB, with an in-memory fallback) holding the snapshot, the open shift with its
// receipt counter, and the queue of sales; a sync loop in the outbox; a heartbeat behind the Online pill; a one-tab lock;
// the guards on closing a shift and signing out; and the service worker registration. When the page is loaded from the
// worker's saved copy while offline, the app starts from what the store holds.

import { createApi, ApiError } from './api.js';
import { createOutbox } from './outbox.js';
import { createSnapshotStore } from './snapshot.js';
import { createStorage } from './storage.js';
import { createStatusBar } from './status.js';
import { createBanner } from './banner.js';
import { emptyTicket } from './ticket.js';
import { confirmDialog, refusalDialog } from './dialog.js';
import { openSignIn } from './signin.js';
import { openReceiptDialog } from './receipt-dialog.js';
import { createCheckout } from './checkout.js';
import { createTerminalScreen } from './terminal.js';
import { createShiftScreen } from './shift.js';
import { createSellScreen } from './sell.js';
import { createReceiptsScreen } from './receipts.js';
import { createSyncScreen } from './sync.js';
import { createCloseScreen } from './close.js';
import { createMemoryStore } from './store.js';
import { openIdbStore } from './idb.js';
import { saveShift, readShift, clearShift, peekReceiptNumber } from './counter.js';
import { createHeartbeat } from './heartbeat.js';
import { createTabLock, TabBusyError } from './lock.js';
import { registerWorker, forgetShell } from './swclient.js';
import { warmThumbnails } from './thumbs.js';
import { closeShiftGuard, signOutGuard, needsConnection, SYNC_HASH } from './guards.js';
import { $, $$ } from './dom.js';

const SCREENS = ['terminal', 'shift', 'sell', 'receipts', 'sync', 'close'];

/** Errors whose own message is meant for the cashier (the others get the generic text). */
const FRIENDLY = ['OutboxStorageError', 'NoShiftError', 'StoreError', 'TabBusyError', 'GuardError'];

async function main() {
  const config = JSON.parse(document.getElementById('pos-config').textContent);

  // The local store: IndexedDB, or memory when this browser will not open it (private mode, blocked). In memory the
  // POS still works online, but a queued sale would be lost if the tab closed, so a standing notice says so.
  let store;
  let storeProblem = '';
  try {
    store = await openIdbStore({ scope: config.posBase });
  } catch {
    store = createMemoryStore();
    storeProblem = 'This browser would not open its local storage, so sales waiting to be sent are kept in memory only. Do not close this tab while sales are waiting.';
  }

  const state = {
    // Empty when the page came from the service worker's saved copy (it never keeps a name); restored from the shift below.
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
  const tabLock = createTabLock();
  const live = document.getElementById('pos-live');
  let status = null;
  let banner = null;
  let heartbeat = null;
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
      outbox.resume().catch(() => {});
    },
    onNetwork: (reachable) => heartbeat?.report(reachable),
  });

  const outbox = createOutbox({ api, store, isOnline: () => state.online });
  const snapshot = createSnapshotStore(api, {
    store,
    onDownloaded: (data) => {
      // Pictures for the products, in the background; a failure is ignored and nothing waits for it.
      setTimeout(() => warmThumbnails(data, { mediaBase: config.mediaBase, isOnline: () => state.online }).catch(() => {}), 0);
    },
  });
  heartbeat = createHeartbeat({
    ping: () => api.ping(),
    onChange: (online) => {
      syncOnline();
      if (online) outbox.sync({ force: true }).catch(() => {});
    },
  });

  function syncOnline() {
    state.online = heartbeat.online();
    status?.update();
    banner?.update();
  }

  /** What a call that needs the server says when there is no connection. */
  const offline = (action) => new ApiError({ network: true, message: needsConnection(action) });

  // ---------------------------------------------------------------- the stored shift

  async function rememberShift(shift, nextReceiptNumber) {
    return saveShift(store, {
      terminalId: state.terminal.id,
      terminalCode: state.terminal.code,
      shift,
      nextReceiptNumber,
      cashier: { id: state.user.id, name: state.user.name },
    });
  }

  /** Fills in who is signed in when the page came from the saved copy and the server can be asked. */
  async function ensureIdentity() {
    if (state.user.id !== '') return;
    const me = await api.get('/auth/staff/me');
    state.user = { id: me.user.id, name: me.user.full_name };
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
      if (FRIENDLY.includes(error?.name)) return error.message;
      if (!(error instanceof ApiError)) return 'Something went wrong. Please try again.';
      const details = [...new Set(Object.values(error.fields ?? {}).filter((text) => typeof text === 'string'))];
      return details.length > 0 && !details.includes(error.message) ? `${error.message} ${details.join(' ')}` : error.message;
    },

    navigate(screen) {
      if (location.hash === `#${screen}`) route();
      else location.hash = screen;
    },

    /** The rules that keep an unsent sale from being stranded; each reads the queue from the store right now. */
    guards: {
      closeShift: async () => closeShiftGuard(await outbox.list(), state.shift.id),
      signOut: async () => signOutGuard(await outbox.list()),
    },

    /**
     * Brings the shift up to date. Online, the server decides (and the open shift is stored with its receipt counter);
     * offline, the shift stored on this device is used, so selling carries on.
     */
    async refreshShift() {
      const terminalId = state.terminal.id;
      let data;
      try {
        await ensureIdentity();
        data = await api.currentShift(terminalId);
      } catch (error) {
        if (!error.network) throw error;
        const record = await readShift(store, terminalId);
        if (record === null) throw offline('Opening a shift');
        if (state.user.id === '') state.user = { id: record.cashier_user_id, name: record.cashier_name };
        applyShift(record.shift, peekReceiptNumber(record));
        return;
      }

      if (data.shift !== null && data.shift.cashier_user_id === state.user.id) {
        // My shift: keep it, with the receipt counter, for offline starts. The counter never goes back for the same shift.
        const record = await rememberShift(data.shift, data.next_receipt_number);
        applyShift(data.shift, peekReceiptNumber(record));
        return;
      }
      if (data.shift === null) await clearShift(store, terminalId).catch(() => {});
      applyShift(data.shift, data.next_receipt_number);
    },

    async openShift(openingCash) {
      if (!state.online) throw offline('Opening a shift');
      let data;
      try {
        data = await api.openShift(state.terminal.id, openingCash);
      } catch (error) {
        throw error.network ? offline('Opening a shift') : error;
      }
      // A fresh shift starts from fresh prices and stock, and its first receipt number is stored.
      await snapshot.load(state.terminal.id).catch(() => {});
      banner.update();
      const record = await rememberShift(data.shift, data.next_receipt_number);
      applyShift(data.shift, peekReceiptNumber(record));
      ctx.navigate('sell');
    },

    async closeShift(countedCash) {
      const guard = await ctx.guards.closeShift();
      if (!guard.allowed) throw Object.assign(new Error(guard.message), { name: 'GuardError', guard });
      if (!state.online) throw offline('Closing the shift');

      let data;
      try {
        data = await api.closeShift(state.shift.id, countedCash);
      } catch (error) {
        throw error.network ? offline('Closing the shift') : error;
      }
      await clearShift(store, state.terminal.id).catch(() => {});
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

      // One tab per organization and terminal.
      if (!(await tabLock.claim(config.posBase, terminal.id))) {
        showLocked();
        throw new TabBusyError();
      }

      // Online this downloads the snapshot (and stores it); offline, or if the download fails, the stored copy is used.
      const data = await snapshot.load(terminal.id);
      state.terminal = { ...terminal, ...data.terminal };
      storage.rememberTerminal(terminal.id);
      banner.update();
      await ctx.refreshShift();
      ctx.navigate(state.shift !== null && state.shiftIsMine ? 'sell' : 'shift');
    },

    /** Shows a receipt from its saved fields: the one just printed, or a reprint. */
    async showReceipt(data, { newSale = false, review = null, queued = false } = {}) {
      await openReceiptDialog(ctx, { data, review, newSale, queued });
      if (newSale) sell.focusSearch();
    },

    banner: { update: () => banner.update() },
  };

  const checkout = createCheckout({ ...ctx, status: { update: () => status.update() } });
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
    sync: createSyncScreen(ctx),
    close: createCloseScreen(ctx),
  };

  /** Where the person may be, given what is true: a terminal first, then an open shift of their own for selling. */
  function allowed(wanted) {
    // The sync screen holds sales that are safe on this device whatever else is true, so it is always reachable.
    if (wanted === 'sync') return 'sync';
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

  /** The second tab: nothing but the message. */
  function showLocked() {
    $('#pos-screens').hidden = true;
    $('#pos-tabs').hidden = true;
    $('#pos-locked').hidden = false;
    $('#title-locked').focus();
  }

  // ---------------------------------------------------------------- start

  status = createStatusBar({ config, state, outbox });
  banner = createBanner({ snapshot, state });
  if (storeProblem !== '') banner.setNotice(storeProblem);

  $('#pos-locked-reload').addEventListener('click', () => location.reload());
  window.addEventListener('hashchange', route);
  window.addEventListener('online', () => { heartbeat.browserChanged(); syncOnline(); });
  window.addEventListener('offline', () => { heartbeat.browserChanged(); syncOnline(); });
  outbox.subscribe(() => status.update());

  // Sign out: refused while any sale waits; do not throw a ticket away without asking; forget the saved shell page first.
  const signOut = $('.pos-status__signout');
  signOut.addEventListener('submit', async (event) => {
    if (signOut.dataset.confirmed === 'yes') return;
    event.preventDefault();

    const guard = await ctx.guards.signOut();
    if (!guard.allowed) {
      await refusalDialog({ title: 'Sign out is not possible yet', message: guard.message, linkHref: SYNC_HASH, linkLabel: 'Open the sync screen' });
      return;
    }
    if (!state.online) {
      await refusalDialog({ title: 'Sign out is not possible yet', message: needsConnection('Signing out') });
      return;
    }
    if (state.ticket.lines.length > 0) {
      const yes = await confirmDialog({ title: 'Sign out?', message: 'The ticket in progress will be lost.', confirmLabel: 'Sign out', danger: true });
      if (!yes) return;
    }

    try {
      await api.ensureCsrf();
    } catch {
      // The sign-out request will say what is wrong.
    }
    $('[data-csrf-field]', signOut).value = api.csrfToken();
    await forgetShell(config.posBase);
    signOut.dataset.confirmed = 'yes';
    signOut.requestSubmit();
  });

  status.start();
  heartbeat.start();
  banner.update();
  setInterval(() => banner.update(), 60 * 1000);
  await outbox.init().catch(() => {});
  outbox.start();
  registerWorker(config.posBase);
  await start();
  // Sales saved before this page loaded (a closed tab, a crash) go out as soon as the page is up.
  if (outbox.counts().sendable > 0) outbox.wake().catch(() => {});

  async function start() {
    // The saved shell has no name in it: take the cashier from the shift stored for the remembered terminal.
    const remembered = storage.lastTerminal();
    if (remembered !== null && state.user.id === '') {
      const record = await readShift(store, remembered).catch(() => null);
      if (record !== null) state.user = { id: record.cashier_user_id, name: record.cashier_name };
      status.update();
    }

    // A remembered terminal on this device skips the choice, unless the person asked for the terminal list.
    if (location.hash !== '#terminal' && remembered !== null) {
      let terminal = null;
      try {
        const terminals = await api.terminals();
        state.terminals = terminals;
        terminal = terminals.find((candidate) => candidate.id === remembered) ?? null;
      } catch (error) {
        // No connection: the terminal stored with the snapshot is enough to start selling on the stored shift.
        if (error.network) terminal = (await snapshot.stored(remembered))?.snapshot.terminal ?? null;
      }
      if (terminal !== null) {
        try {
          await ctx.chooseTerminal(terminal);
          return;
        } catch (error) {
          if (error instanceof TabBusyError) return;
          // Fall through to the terminal screen, which shows the problem and a Try again button.
        }
      }
    }
    route();
  }
}

main();
