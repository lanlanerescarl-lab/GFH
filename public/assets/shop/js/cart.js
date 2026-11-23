// The browser's cart: what is stored, and the quantity rules a line must follow. No DOM and no network here, so Node
// can test every rule. The server stays the authority: it prices the cart and refuses anything invalid again.
//
// What is stored, and the only thing that is ever stored, per organization (the key includes its slug):
//   {"version":1,"lines":[{"variant_id":"<uuid>","quantity":"2"}]}
// No names, prices, emails or tokens. Everything else on the cart page comes from the server's price answer.

export const STORAGE_VERSION = 1;
export const MAX_LINES = 50;
// One million units, in thousandths: the same ceiling the server uses.
export const MAX_QUANTITY_MILLI = 1000000000;

const VARIANT_ID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const QUANTITY = /^\d{1,7}(\.\d{1,3})?$/;

/** The storage key for one organization's cart. */
export const storageKey = (slug) => `gfh-cart:${slug}`;

export const isVariantId = (value) => typeof value === 'string' && VARIANT_ID.test(value);

/** "1.5" or 2 as thousandths (1500), or null when it is not a plain positive number with up to three decimals. */
export function toMilli(value) {
  const text = typeof value === 'number' ? String(value) : value;
  if (typeof text !== 'string' || !QUANTITY.test(text.trim())) {
    return null;
  }
  const [whole, fraction = ''] = text.trim().split('.');
  return Number(whole) * 1000 + Number(fraction.padEnd(3, '0'));
}

/** Thousandths as the shortest text: 1500 -> "1.5", 2000 -> "2". */
export function fromMilli(milli) {
  const whole = Math.floor(milli / 1000);
  const fraction = String(milli % 1000).padStart(3, '0').replace(/0+$/, '');
  return fraction === '' ? String(whole) : `${whole}.${fraction}`;
}

const plain = (milli) => fromMilli(milli);

/**
 * Checks one quantity against a variant's rule. The rule comes from the server's data, never from this file:
 * { decimal: boolean, step: "0.5", min: "1" }. As on the server, the step applies to decimal variants only
 * (a whole-number variant takes whole numbers), and the minimum applies to every variant.
 *
 * @returns {{ok: true, milli: number} | {ok: false, error: string, message: string}}
 */
export function checkQuantity(value, rules) {
  const milli = toMilli(value);
  if (milli === null) {
    return { ok: false, error: 'invalid_quantity', message: 'Enter a valid quantity (up to 3 decimals).' };
  }
  if (milli <= 0) {
    return { ok: false, error: 'invalid_quantity', message: 'The quantity must be greater than zero.' };
  }
  if (milli > MAX_QUANTITY_MILLI) {
    return { ok: false, error: 'too_large', message: 'That quantity is too large.' };
  }
  const decimal = Boolean(rules?.decimal);
  if (!decimal && milli % 1000 !== 0) {
    return { ok: false, error: 'whole_number', message: 'Use a whole number.' };
  }
  if (decimal) {
    const step = toMilli(rules?.step ?? '1') || 1000;
    if (milli % step !== 0) {
      return { ok: false, error: 'step', message: `Use a multiple of ${plain(step)}.` };
    }
  }
  const min = toMilli(rules?.min ?? '1') || 1000;
  if (milli < min) {
    return { ok: false, error: 'below_minimum', message: `The minimum is ${plain(min)}.` };
  }
  return { ok: true, milli };
}

/**
 * Reads what is in storage. Anything that is not exactly this module's own format is discarded as a whole:
 * unreadable text, another version, more than MAX_LINES lines, a line without a valid variant id and quantity, or the
 * same variant twice. Extra keys on a line (a price someone pasted in) are dropped, so they never survive a save.
 *
 * @returns {{variant_id: string, quantity: string}[]}
 */
export function parseCart(raw) {
  if (typeof raw !== 'string' || raw === '') {
    return [];
  }
  let data;
  try {
    data = JSON.parse(raw);
  } catch {
    return [];
  }
  if (data === null || typeof data !== 'object' || data.version !== STORAGE_VERSION || !Array.isArray(data.lines)) {
    return [];
  }
  if (data.lines.length > MAX_LINES) {
    return [];
  }

  const seen = new Set();
  const lines = [];
  for (const line of data.lines) {
    if (line === null || typeof line !== 'object' || !isVariantId(line.variant_id)) {
      return [];
    }
    const milli = toMilli(line.quantity);
    const id = line.variant_id.toLowerCase();
    if (milli === null || milli <= 0 || milli > MAX_QUANTITY_MILLI || seen.has(id)) {
      return [];
    }
    seen.add(id);
    lines.push({ variant_id: id, quantity: fromMilli(milli) });
  }
  return lines;
}

/** The exact text that is stored: version and lines of variant id and quantity, nothing else. */
export function serializeCart(lines) {
  return JSON.stringify({
    version: STORAGE_VERSION,
    lines: lines.map((line) => ({ variant_id: line.variant_id, quantity: line.quantity })),
  });
}

/** Reads the cart from a storage object (localStorage, or a stand-in). Storage that throws is an empty cart. */
export function loadCart(storage, slug) {
  try {
    return parseCart(storage.getItem(storageKey(slug)));
  } catch {
    return [];
  }
}

/** Saves the cart; returns false if the browser refused (storage full or blocked). An empty cart removes the key. */
export function saveCart(storage, slug, lines) {
  try {
    if (lines.length === 0) {
      storage.removeItem(storageKey(slug));
    } else {
      storage.setItem(storageKey(slug), serializeCart(lines));
    }
    return true;
  } catch {
    return false;
  }
}

/**
 * Adds a quantity of a variant. The same variant adds to its existing line. The result still has to follow the rule,
 * and a new line is refused once the cart holds MAX_LINES lines.
 *
 * @returns {{ok: true, lines: object[]} | {ok: false, error: string, message: string}}
 */
export function addToCart(lines, variantId, quantity, rules) {
  if (!isVariantId(variantId)) {
    return { ok: false, error: 'invalid_variant', message: 'That product cannot be added.' };
  }
  const id = variantId.toLowerCase();
  const added = checkQuantity(quantity, rules);
  if (!added.ok) {
    return added;
  }

  const index = lines.findIndex((line) => line.variant_id === id);
  if (index === -1) {
    if (lines.length >= MAX_LINES) {
      return { ok: false, error: 'cart_full', message: `The cart holds at most ${MAX_LINES} different products.` };
    }
    return { ok: true, lines: [...lines, { variant_id: id, quantity: fromMilli(added.milli) }] };
  }

  const total = checkQuantity(fromMilliSafe(toMilli(lines[index].quantity) + added.milli), rules);
  if (!total.ok) {
    return total;
  }
  const next = lines.map((line, position) => (position === index ? { variant_id: id, quantity: fromMilli(total.milli) } : line));
  return { ok: true, lines: next };
}

/** Replaces a line's quantity (the cart page's quantity control). */
export function setQuantity(lines, variantId, quantity, rules) {
  const checked = checkQuantity(quantity, rules);
  if (!checked.ok) {
    return checked;
  }
  const id = String(variantId).toLowerCase();
  if (!lines.some((line) => line.variant_id === id)) {
    return { ok: false, error: 'not_in_cart', message: 'That product is not in the cart.' };
  }
  return {
    ok: true,
    lines: lines.map((line) => (line.variant_id === id ? { variant_id: id, quantity: fromMilli(checked.milli) } : line)),
  };
}

export function removeLine(lines, variantId) {
  const id = String(variantId).toLowerCase();
  return lines.filter((line) => line.variant_id !== id);
}

/** How many different products are in the cart (what the header badge shows). */
export const lineCount = (lines) => lines.length;

// A sum can pass the ceiling; keep it a number the rule check can refuse instead of producing "NaN".
function fromMilliSafe(milli) {
  return milli > MAX_QUANTITY_MILLI ? '1000000.001' : fromMilli(milli);
}
