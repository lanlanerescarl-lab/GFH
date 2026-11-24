// The rules that keep an unsent sale from being stranded. Pure functions over the queue's records, no DOM.
//
// A sale is "waiting" while it is queued, being sent, or needs attention (the server refused it and a manager has to
// look). Closing the shift or signing out with a sale waiting would leave it with nobody able to send it, so both are
// refused until it is sent. The messages are plain text; the screens add a link to the sync screen (#sync).

export const WAITING_STATES = ['queued', 'sending', 'needs_attention'];

export const SYNC_HASH = '#sync';

/** The queue's records that are still waiting, optionally only those of one shift. */
export function waitingSales(records, { shiftId = null } = {}) {
  return records.filter((record) => WAITING_STATES.includes(record.state) && (shiftId === null || record.shift_id === shiftId));
}

const sales = (count) => `${count} ${count === 1 ? 'sale is' : 'sales are'}`;
const them = (count) => (count === 1 ? 'it' : 'them');

/**
 * Whether the shift may be closed.
 * @returns {{allowed: boolean, count: number, message: string}}
 */
export function closeShiftGuard(records, shiftId) {
  const count = waitingSales(records, { shiftId }).length;
  return count === 0
    ? { allowed: true, count, message: '' }
    : { allowed: false, count, message: `${sales(count)} of this shift still waiting to be sent. Send ${them(count)} before closing the shift.` };
}

/**
 * Whether the person may sign out. Any waiting sale blocks it, whatever shift it belongs to.
 * @returns {{allowed: boolean, count: number, message: string}}
 */
export function signOutGuard(records) {
  const count = waitingSales(records).length;
  return count === 0
    ? { allowed: true, count, message: '' }
    : { allowed: false, count, message: `${sales(count)} still waiting to be sent. Send ${them(count)} before signing out.` };
}

/** What to say when an action that needs the server is tried without a connection. */
export function needsConnection(action) {
  return `${action} needs a connection to the server, and there is none right now. Connect to the internet and try again.`;
}
