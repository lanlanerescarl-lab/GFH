// How old the stored snapshot (prices and stock) is, and what the banner says about it. Pure functions, no DOM.
//
// Selling is never blocked by age: past 24 hours the banner turns into a warning, and that is all.

export const STALE_AFTER_MS = 24 * 60 * 60 * 1000;

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;

/** Milliseconds since the snapshot was downloaded; 0 for a clock that went backwards. */
export function snapshotAgeMs(downloadedAt, nowMs) {
  return Math.max(0, nowMs - downloadedAt);
}

/** "less than a minute", "5 minutes", "3 hours", "2 days": the age in words, rounded down. */
export function formatAge(ageMs) {
  if (ageMs < MINUTE) return 'less than a minute';
  if (ageMs < HOUR) return plural(Math.floor(ageMs / MINUTE), 'minute');
  if (ageMs < DAY) return plural(Math.floor(ageMs / HOUR), 'hour');
  return plural(Math.floor(ageMs / DAY), 'day');
}

/**
 * What to show above the screens.
 *
 * @param {{downloadedAt: number|null, nowMs: number, online: boolean, source?: 'network'|'stored'|null}} input
 * @returns {{level: 'none'|'ok'|'warning'|'missing', text: string, ageMs: number|null}}
 *   none: nothing to say yet; ok: a quiet note with the age; warning: older than 24 hours;
 *   missing: no stored snapshot and no connection.
 */
export function snapshotBanner({ downloadedAt, nowMs, online, source = null }) {
  if (downloadedAt === null || downloadedAt === undefined) {
    return online
      ? { level: 'none', text: '', ageMs: null }
      : { level: 'missing', text: 'There are no saved prices and stock on this device, and there is no connection. Connect to the internet to load them.', ageMs: null };
  }

  const ageMs = snapshotAgeMs(downloadedAt, nowMs);
  const age = formatAge(ageMs);
  if (ageMs > STALE_AFTER_MS) {
    return {
      level: 'warning',
      text: `Prices and stock were last updated ${age} ago. Connect to the internet and press Refresh as soon as you can. You can keep selling.`,
      ageMs,
    };
  }

  const where = source === 'stored' && !online ? ' Working offline from the saved copy.' : '';
  return { level: 'ok', text: `Prices and stock were updated ${age} ago.${where}`, ageMs };
}
