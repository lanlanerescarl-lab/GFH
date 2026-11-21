// Receipt numbers: the terminal's code and a counter, like T3-000123 (the same shape the server accepts:
// the code, a hyphen, and 6 to 9 digits). Pure functions, no DOM.

const SHAPE = /^([A-Z0-9]{1,6})-(\d{6,9})$/;

/** T3 and 123 give "T3-000123". */
export function formatReceiptNumber(terminalCode, sequence) {
  if (!Number.isInteger(sequence) || sequence < 1 || sequence > 999999999) {
    throw new RangeError('A receipt counter is a whole number from 1 to 999999999.');
  }
  return `${terminalCode}-${String(sequence).padStart(6, '0')}`;
}

/** "T3-000123" to {code: "T3", sequence: 123}; null when the text does not have a receipt number's shape. */
export function parseReceiptNumber(text) {
  const match = SHAPE.exec(String(text ?? ''));
  return match === null ? null : { code: match[1], sequence: Number(match[2]) };
}

/** The number after this one on the same terminal: "T3-000123" gives "T3-000124". Null when the text is not a receipt number. */
export function nextReceiptNumber(text) {
  const parsed = parseReceiptNumber(text);
  return parsed === null ? null : formatReceiptNumber(parsed.code, parsed.sequence + 1);
}
