// The banner above the screens: how old the prices and stock are (and a warning past 24 hours), and a notice when this
// browser could not open its local storage. Only shows state; the app calls update() when something changed and once a
// minute so the age keeps moving. Text goes in with textContent. Selling is never blocked by what it says.

import { $, h, clear, append } from './dom.js';
import { snapshotBanner } from './freshness.js';

/**
 * @param {object} options
 * @param {{info: () => object}} options.snapshot
 * @param {{online: boolean}} options.state
 * @param {() => number} [options.now]
 */
export function createBanner({ snapshot, state, now = () => Date.now() }) {
  const root = $('#pos-banner');
  let notice = '';

  function update() {
    const info = snapshot.info();
    const age = snapshotBanner({ downloadedAt: info.downloadedAt, nowMs: now(), online: state.online, source: info.source });
    const lines = [];
    if (age.level !== 'none') lines.push(h('p', { class: `pos-banner__line pos-banner__line--${age.level}`, 'data-banner': 'age' }, age.text));
    if (notice !== '') lines.push(h('p', { class: 'pos-banner__line pos-banner__line--warning', 'data-banner': 'notice' }, notice));

    root.hidden = lines.length === 0;
    root.classList.toggle('pos-banner--warning', age.level === 'warning' || age.level === 'missing' || notice !== '');
    append(clear(root), lines);
  }

  return {
    update,
    /** A standing message, such as "sales are kept in memory only"; an empty text removes it. */
    setNotice(text) {
      notice = text;
      update();
    },
  };
}
