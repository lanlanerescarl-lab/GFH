// The ticket: the lines of the sale being rung up. Pure functions, no DOM; every function returns a new ticket
// and leaves the old one alone. Quantities are whole thousandths.
//
// Quantity rules mirror core/Support/QuantityRules.php::forSale(): more than zero, at most one million, whole
// numbers unless the product allows decimals, a multiple of the product's step when it does, and at least its minimum.
// A ticket never refuses a line for stock: an oversell is allowed and the server flags it for review.

import { parseQuantity, formatQuantity } from './money.js';

export const MAX_QUANTITY_MILLI = 1000000000;

export function emptyTicket() {
  return { lines: [] };
}

/** How a product's quantity behaves, in thousandths: whether decimals are allowed, the step, the minimum, and one "unit". */
export function quantityRules(product) {
  const decimals = Boolean(product.allow_decimal_qty);
  const step = decimals ? (parseQuantity(product.qty_step) ?? 1000) : 1000;
  const minimum = parseQuantity(product.min_qty) ?? 1000;
  return { decimals, stepMilli: step, minMilli: minimum, unitMilli: step };
}

/** A message for a quantity the server would refuse, or null when it is fine. Same wording as the server. */
export function validateQuantity(product, milli) {
  const rules = quantityRules(product);
  if (!Number.isInteger(milli)) return 'Enter a valid quantity (up to 3 decimals).';
  if (milli <= 0) return 'The quantity must be greater than zero.';
  if (milli > MAX_QUANTITY_MILLI) return 'That quantity is too large.';
  if (!rules.decimals && milli % 1000 !== 0) return 'Use a whole number.';
  if (rules.decimals && milli % rules.stepMilli !== 0) return `Use a multiple of ${formatQuantity(rules.stepMilli)}.`;
  if (milli < rules.minMilli) return `The minimum is ${formatQuantity(rules.minMilli)}.`;
  return null;
}

/** The quantity a product starts at on the ticket: its minimum, rounded up to its step. */
export function firstQuantity(product) {
  const { stepMilli, minMilli } = quantityRules(product);
  return Math.ceil(minMilli / stepMilli) * stepMilli;
}

function find(ticket, variantId) {
  return ticket.lines.find((line) => line.variantId === variantId) ?? null;
}

function withQuantity(ticket, variantId, quantityMilli) {
  return { lines: ticket.lines.map((line) => (line.variantId === variantId ? { ...line, quantityMilli } : line)) };
}

/** Quantity of a product on the ticket, in thousandths (0 when it is not there). */
export function quantityOf(ticket, variantId) {
  return find(ticket, variantId)?.quantityMilli ?? 0;
}

/**
 * One more of a product: a new line at its first quantity, or one more unit on the line already there (a product is
 * never listed twice, so a quantity tier sees the true total). Returns {ticket, error}; error is null when it worked.
 */
export function addProduct(ticket, product) {
  const existing = find(ticket, product.variant_id);
  if (existing === null) {
    const quantityMilli = firstQuantity(product);
    const error = validateQuantity(product, quantityMilli);
    return error === null
      ? { ticket: { lines: [...ticket.lines, { variantId: product.variant_id, quantityMilli }] }, error: null }
      : { ticket, error };
  }

  return setQuantity(ticket, product, existing.quantityMilli + quantityRules(product).unitMilli);
}

/** Plus (direction 1) or minus (direction -1) by one unit. Minus stops at the minimum; Remove is a separate action. */
export function adjustQuantity(ticket, product, direction) {
  const existing = find(ticket, product.variant_id);
  if (existing === null) return { ticket, error: null };
  return setQuantity(ticket, product, existing.quantityMilli + direction * quantityRules(product).unitMilli);
}

/** Sets a line's quantity (numeric entry). An invalid quantity leaves the ticket as it was and says why. */
export function setQuantity(ticket, product, quantityMilli) {
  const error = validateQuantity(product, quantityMilli);
  if (error !== null) return { ticket, error };
  if (find(ticket, product.variant_id) === null) return { ticket, error: null };
  return { ticket: withQuantity(ticket, product.variant_id, quantityMilli), error: null };
}

export function removeLine(ticket, variantId) {
  return { lines: ticket.lines.filter((line) => line.variantId !== variantId) };
}

/** The ticket's lines with their products, ready for pricing. A line whose product left the snapshot is dropped. */
export function pricingEntries(ticket, productsByVariant) {
  return ticket.lines
    .filter((line) => productsByVariant.has(line.variantId))
    .map((line) => ({ product: productsByVariant.get(line.variantId), quantityMilli: line.quantityMilli }));
}

/** How many separate lines are on the ticket. */
export function lineCount(ticket) {
  return ticket.lines.length;
}

/**
 * Whether a discount rule asks for the last four characters of the customer's ID. The server accepts the reference
 * for any rule and never requires it, but it stores it for the VAT-exempt senior and PWD rules, so those ask for it.
 */
export function discountNeedsId(rule) {
  return Boolean(rule && rule.vat_exempt);
}

/**
 * The last four characters of an ID, as the server accepts them: 1 to 4 letters or digits. The full ID number is
 * never entered, so anything longer is refused rather than cut down.
 */
export function checkIdLast4(text) {
  const value = String(text ?? '').trim();
  if (value === '') return { ok: false, value, message: 'Enter the last 4 characters of the ID.' };
  if (!/^[A-Za-z0-9]+$/.test(value)) return { ok: false, value, message: 'Use letters and digits only.' };
  if (value.length > 4) return { ok: false, value, message: 'Enter only the last 4 characters of the ID, never the whole number.' };
  return { ok: true, value: value.toUpperCase(), message: null };
}
