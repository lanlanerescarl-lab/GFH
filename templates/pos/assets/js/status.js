// The status bar shown on every screen, and the row of screen tabs under it. It only shows state; the app tells it
// when something changed. The waiting-sales badge reads outbox.pendingCount() and links to the sync screen (#sync); it
// turns to the attention colour when the server refused a sale and a manager has to look.

import { h, $, clear, append } from './dom.js';
import { formatClock, formatTime } from './datetime.js';

const TABS = [
  { screen: 'sell', label: 'Sell' },
  { screen: 'receipts', label: 'Receipts' },
  { screen: 'sync', label: 'Sync' },
  { screen: 'shift', label: 'Shift' },
  { screen: 'close', label: 'Close shift' },
  { screen: 'terminal', label: 'Terminal' },
];

export function createStatusBar({ config, state, outbox, now = () => new Date() }) {
  const root = document.getElementById('pos-status');
  const tabs = document.getElementById('pos-tabs');
  const field = (name) => $(`[data-status="${name}"]`, root);

  field('organization').textContent = config.organization;
  let timer = null;

  function shiftText() {
    if (state.shift === null) return 'No shift';
    if (!state.shiftIsMine) return "Another cashier's shift is open";
    return `Open since ${formatTime(state.shift.opened_at)}`;
  }

  function update() {
    field('terminal').textContent = state.terminal === null ? 'Not chosen' : state.terminal.code;
    field('cashier').textContent = state.user.name;
    field('shift').textContent = shiftText();

    const online = field('online');
    online.textContent = state.online ? 'Online' : 'Offline';
    online.classList.toggle('pos-pill--ok', state.online);
    online.classList.toggle('pos-pill--warn', !state.online);

    const waiting = field('waiting');
    const count = outbox.pendingCount();
    waiting.hidden = false;
    waiting.textContent = `${count} waiting`;
    waiting.setAttribute('aria-label', `${count} ${count === 1 ? 'sale' : 'sales'} waiting to be sent. Open the sync screen.`);
    const attention = outbox.counts().attention;
    waiting.classList.toggle('pos-pill--attention', attention > 0);
    waiting.classList.toggle('pos-pill--warn', count > 0 && attention === 0);
    waiting.classList.toggle('pos-pill--quiet', count === 0);
    if (attention > 0) waiting.setAttribute('aria-label', `${count} ${count === 1 ? 'sale' : 'sales'} waiting to be sent, ${attention} needing attention. Open the sync screen.`);
  }

  function tick() {
    field('clock').textContent = formatClock(now());
  }

  /** Draws the screen tabs. Only the screens that make sense right now are offered. */
  function renderTabs(current) {
    if (state.terminal === null) {
      tabs.hidden = true;
      return;
    }
    tabs.hidden = false;
    const shiftOpen = state.shift !== null;
    const mine = shiftOpen && state.shiftIsMine;
    // Selling and receipts need my own open shift; closing is offered for any open shift (the server decides who may close it).
    const offered = { sell: mine, receipts: mine, sync: true, close: shiftOpen, shift: true, terminal: true };
    const list = h('ul', { class: 'pos-tabs__list' });
    for (const tab of TABS) {
      if (!offered[tab.screen]) continue;
      append(list, h('li', {}, h('a', { class: 'pos-tab', href: `#${tab.screen}`, 'aria-current': tab.screen === current ? 'page' : null }, tab.label)));
    }
    append(clear(tabs), [list]);
  }

  return {
    update,
    renderTabs,
    start() {
      tick();
      timer = setInterval(tick, 1000);
      update();
    },
    stop() {
      clearInterval(timer);
    },
  };
}
