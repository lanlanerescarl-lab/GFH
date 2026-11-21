// Dates for the POS: DD Mon YYYY, 12-hour time, always in Asia/Manila. Pure functions, no DOM.

export const TIME_ZONE = 'Asia/Manila';
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * The server sends PostgreSQL-style times ("2026-11-20 14:30:00+08"), which not every browser reads.
 * This turns one into an ISO 8601 text; an ISO text passes through. Returns a Date, or null when unreadable.
 */
export function parseServerTime(value) {
  if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
  const match = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(\.\d+)?(Z|[+-]\d{2}(?::?\d{2})?)?$/.exec(String(value ?? '').trim());
  if (match === null) return null;
  let zone = match[4] ?? 'Z';
  if (zone !== 'Z') {
    const digits = zone.slice(1).replace(':', '');
    zone = `${zone[0]}${digits.slice(0, 2)}:${digits.slice(2, 4) || '00'}`;
  }
  const date = new Date(`${match[1]}T${match[2]}${(match[3] ?? '').slice(0, 4)}${zone}`);
  return Number.isNaN(date.getTime()) ? null : date;
}

function manilaParts(date) {
  const parts = {};
  for (const part of new Intl.DateTimeFormat('en-US', {
    timeZone: TIME_ZONE, year: 'numeric', month: 'numeric', day: '2-digit',
    hour: 'numeric', minute: '2-digit', second: '2-digit', hourCycle: 'h12',
  }).formatToParts(date)) {
    parts[part.type] = part.value;
  }
  return parts;
}

/** "20 Nov 2026" */
export function formatDate(value) {
  const date = parseServerTime(value);
  if (date === null) return '';
  const p = manilaParts(date);
  return `${p.day} ${MONTHS[Number(p.month) - 1]} ${p.year}`;
}

/** "2:05 PM" */
export function formatTime(value) {
  const date = parseServerTime(value);
  if (date === null) return '';
  const p = manilaParts(date);
  return `${p.hour}:${p.minute} ${p.dayPeriod.toUpperCase()}`;
}

/** "2:05:09 PM", for the status bar clock. */
export function formatClock(value) {
  const date = parseServerTime(value);
  if (date === null) return '';
  const p = manilaParts(date);
  return `${p.hour}:${p.minute}:${p.second} ${p.dayPeriod.toUpperCase()}`;
}

/** "20 Nov 2026, 2:05 PM" */
export function formatDateTime(value) {
  const date = formatDate(value);
  return date === '' ? '' : `${date}, ${formatTime(value)}`;
}
