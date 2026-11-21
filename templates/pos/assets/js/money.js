// Money and quantity text for the POS. Pure functions, no DOM.
// Money is whole centavos and quantities are whole thousandths, so nothing here ever uses floating point.

export const PESO = '\u20B1';

/** "112", "112.5" or "112.50" to centavos; null when the text is not a plain amount with at most two decimals. */
export function parseMoney(text) {
  const match = /^(\d{1,12})(?:\.(\d{1,2}))?$/.exec(String(text ?? '').trim());
  if (match === null) return null;
  return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

/** Centavos to the API's text: 11200 is "112.00". */
export function toApiMoney(centavos) {
  const sign = centavos < 0 ? '-' : '';
  const abs = Math.abs(centavos);
  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

/** Centavos for people: 123450 is "₱1,234.50". */
export function formatPeso(centavos) {
  const sign = centavos < 0 ? '-' : '';
  const abs = Math.abs(centavos);
  const pesos = String(Math.floor(abs / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return `${sign}${PESO}${pesos}.${String(abs % 100).padStart(2, '0')}`;
}

/** "2", "0.25" or "12.345" to thousandths; null when the text is not a plain quantity with at most three decimals. */
export function parseQuantity(text) {
  const match = /^(\d{1,9})(?:\.(\d{1,3}))?$/.exec(String(text ?? '').trim());
  if (match === null) return null;
  return Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'));
}

/** Thousandths to the API's text: 2500 is "2.500". */
export function toApiQuantity(milli) {
  return `${Math.floor(milli / 1000)}.${String(milli % 1000).padStart(3, '0')}`;
}

/** Thousandths for people, without needless zeros: 2500 is "2.5" and 4000 is "4". */
export function formatQuantity(milli) {
  const whole = Math.floor(milli / 1000);
  const fraction = String(milli % 1000).padStart(3, '0').replace(/0+$/, '');
  return fraction === '' ? String(whole) : `${whole}.${fraction}`;
}

/** A percent such as "20.00" to hundredths of a percent (2000), like the server reads a rule's percent. */
export function parsePercent(text) {
  const match = /^(\d{1,3})(?:\.(\d{1,2}))?$/.exec(String(text ?? '').trim());
  if (match === null) return null;
  return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

/**
 * One key press on the on-screen keypad. The text is what has been typed so far; the key is a digit, ".",
 * "back" or "clear". An amount keeps at most `decimals` decimals and at most 9 whole digits.
 */
export function keypadPress(text, key, decimals = 2) {
  if (key === 'clear') return '';
  if (key === 'back') return text.slice(0, -1);
  if (key === '.') {
    if (decimals === 0 || text.includes('.')) return text;
    return text === '' ? '0.' : `${text}.`;
  }
  if (!/^\d$/.test(key)) return text;

  const [whole, fraction] = text.split('.');
  if (fraction !== undefined) return fraction.length >= decimals ? text : text + key;
  // No leading zeros: "0" then "5" is "5".
  if (whole === '0') return key;
  return whole.length >= 9 ? text : text + key;
}
