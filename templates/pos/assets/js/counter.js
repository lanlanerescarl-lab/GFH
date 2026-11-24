// The current shift of a terminal, kept on the device, and the receipt counter that lives inside it.
//
// When a shift is opened (or resumed) online, its first receipt number is stored here. Each sale then takes the next
// number inside the same store transaction that writes the sale to the queue: read the counter, build the number,
// write the counter back. The store runs such a transaction alone, so two tabs (or two quick sales) can never get the
// same number. Numbers are built with receiptno.js, so they have the shape the server accepts (T3-000123).
//
// The record holds only what a sale and a receipt need: the shift's own figures and the cashier's id and name as the
// receipt shows them. No token, no password, no session.

import { formatReceiptNumber, parseReceiptNumber } from './receiptno.js';

export const SHIFT_STORE = 'shift';

/** There is no stored shift for this terminal, so no receipt number can be given. */
export class NoShiftError extends Error {
  constructor(message = 'There is no open shift stored on this device for this terminal.') {
    super(message);
    this.name = 'NoShiftError';
  }
}

/** The parts of the server's shift the POS keeps. */
function keepShift(shift) {
  return {
    id: shift.id,
    terminal_id: shift.terminal_id ?? null,
    cashier_user_id: shift.cashier_user_id ?? null,
    opened_at: shift.opened_at ?? null,
    opening_cash: shift.opening_cash ?? null,
    status: shift.status ?? 'open',
  };
}

/**
 * Stores a shift that was just opened or resumed online.
 *
 * The counter never goes backwards for the same shift: sales waiting in the queue have taken numbers the server has
 * not seen yet, so the server's "next number" can be behind. For a different shift (or none stored) the server's
 * number is the start.
 *
 * @param {object} store
 * @param {{terminalId: string, terminalCode: string, shift: object, nextReceiptNumber: string|null, cashier: {id: string, name: string}, savedAt?: string}} input
 * @returns {Promise<object>} the stored record
 */
export async function saveShift(store, { terminalId, terminalCode, shift, nextReceiptNumber, cashier, savedAt = new Date().toISOString() }) {
  const parsed = parseReceiptNumber(nextReceiptNumber);
  const serverSequence = parsed?.sequence ?? 1;

  return store.transaction([SHIFT_STORE], 'readwrite', async (tx) => {
    const existing = await tx.get(SHIFT_STORE, terminalId);
    const sameShift = existing !== undefined && existing.shift.id === shift.id;
    const record = {
      terminal_id: terminalId,
      // The terminal's code is the receipt prefix: the next-number text from the server wins, then what was stored.
      prefix: parsed?.code ?? (sameShift ? existing.prefix : terminalCode),
      shift: keepShift(shift),
      counter: sameShift ? Math.max(existing.counter, serverSequence) : serverSequence,
      cashier_user_id: cashier.id,
      cashier_name: cashier.name,
      saved_at: savedAt,
    };
    await tx.put(SHIFT_STORE, terminalId, record);
    return record;
  });
}

/** The stored shift record of a terminal, or null. */
export async function readShift(store, terminalId) {
  return (await store.get(SHIFT_STORE, terminalId)) ?? null;
}

/** Forgets the stored shift of a terminal (after it was closed). */
export async function clearShift(store, terminalId) {
  await store.delete(SHIFT_STORE, terminalId);
}

/** "T3-000123", the number the next sale on this shift will take, or null when none is stored. Reads only; takes nothing. */
export function peekReceiptNumber(record) {
  return record === null || record === undefined ? null : formatReceiptNumber(record.prefix, record.counter);
}

/**
 * Takes the next receipt number. Must run inside a readwrite transaction that includes the shift store, together with
 * the write that uses the number, so that a failure gives the number back and two callers never share one.
 *
 * @param {{get: Function, put: Function}} tx the transaction
 * @param {string} terminalId
 * @returns {Promise<string>} for example "T3-000123"
 */
export async function takeReceiptNumber(tx, terminalId) {
  const record = await tx.get(SHIFT_STORE, terminalId);
  if (record === undefined) throw new NoShiftError();

  const number = formatReceiptNumber(record.prefix, record.counter);
  await tx.put(SHIFT_STORE, terminalId, { ...record, counter: record.counter + 1 });
  return number;
}
