// Display formatting for money, quantities and dates, with no DOM. Amounts arrive from the server as exact decimal text
// ("1234.50") and are only ever grouped and given the peso sign here; they are never turned into floating-point numbers.
import { fromMilli, toMilli } from './cart.js';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "1234.5" -> "₱1,234.50"; "-12" -> "-₱12.00"; anything that is not a decimal number -> "₱0.00". */
export function formatPeso(amount) {
  const match = /^(-?)(\d{1,16})(?:\.(\d{1,2})\d*)?$/.exec(typeof amount === 'number' ? String(amount) : String(amount ?? '').trim());
  if (match === null) {
    return '₱0.00';
  }
  const whole = match[2].replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const cents = (match[3] ?? '').padEnd(2, '0');
  const negative = match[1] === '-' && (Number(match[2]) !== 0 || Number(cents) !== 0);
  return `${negative ? '-' : ''}₱${whole}.${cents}`;
}

/** "2.000" -> "2"; "0.500" -> "0.5"; anything else is shown as it came. */
export function formatQuantity(quantity) {
  const milli = toMilli(quantity);
  return milli === null ? String(quantity ?? '') : fromMilli(milli);
}

/** "2026-12-05" (or a full ISO time starting with that date) -> "05 Dec 2026". Dates from the server are already Asia/Manila days. */
export function formatDate(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value ?? ''));
  if (match === null) {
    return '';
  }
  const month = MONTHS[Number(match[2]) - 1];
  return month === undefined ? '' : `${match[3]} ${month} ${match[1]}`;
}

/** Whether an amount string is more than zero (used to show a fee only when there is one). */
export function isPositiveAmount(amount) {
  const match = /^(\d{1,16})(?:\.(\d+))?$/.exec(String(amount ?? '').trim());
  return match !== null && (Number(match[1]) > 0 || /[1-9]/.test(match[2] ?? ''));
}

const METHOD_LABELS = {
  cash: 'Cash',
  gcash: 'GCash',
  maya: 'Maya',
  card: 'Card',
  bank_transfer: 'Bank transfer',
  cod: 'Cash on delivery',
};

/** A payment method key as people read it; an unknown key is shown as it came (it is written with textContent anyway). */
export const paymentMethodLabel = (key) => METHOD_LABELS[key] ?? String(key);
