// The checkout page's data rules with no DOM, so Node can test them: the request bodies, the saved-address prefill, the
// mapping of the server's field errors to form fields, and the plain-words stock shortage. It decides nothing about
// price, stock or fees; the server does, and what it says is what the page shows.

export const ADDRESS_FIELDS = ['recipient', 'phone', 'line1', 'barangay', 'city', 'province', 'postal_code'];

import { checkoutValues, fieldKey } from './checkout-fields.js';

const text = (value) => (typeof value === 'string' ? value.trim() : '');

/** The cart lines as the API wants them: variant id and quantity, nothing else. */
export const itemsFrom = (lines) => lines.map((line) => ({ variant_id: line.variant_id, quantity: line.quantity }));

/**
 * The form's values as the checkout API takes them: {method: "pickup", branch_id} or the flat delivery address.
 * @param {{method: string, branch_id?: string, address?: Record<string, string>}} values
 */
export function fulfillmentBody(values) {
  if (values.method === 'delivery') {
    const body = { method: 'delivery' };
    for (const field of ADDRESS_FIELDS) {
      body[field] = text(values.address?.[field]);
    }
    return body;
  }
  return { method: 'pickup', branch_id: text(values.branch_id) };
}

/**
 * The same choice in the shape the server's pricing hooks see when the order is created ({method, address: {...}}), so a
 * fee priced here is the fee charged at checkout. The price call does not validate it; checkout does.
 */
export function pricingFulfillment(values) {
  if (values.method === 'delivery') {
    const flat = fulfillmentBody(values);
    const { method, ...address } = flat;
    return { method, address };
  }
  return fulfillmentBody(values);
}

/**
 * Body for POST /cart/price at checkout. `values.checkout` holds what was entered in the extension fields (id => value);
 * without any it is {} as before. The server keeps only valid values of declared fields.
 */
export const pricingBody = (lines, values) => ({ items: itemsFrom(lines), fulfillment: pricingFulfillment(values), checkout: checkoutValues(values.checkout) });

/** Body for POST /checkout. The server checks every value again and refuses an unknown id. */
export const checkoutBody = (lines, values) => ({ items: itemsFrom(lines), fulfillment: fulfillmentBody(values), checkout: checkoutValues(values.checkout) });

/**
 * The delivery form values from the customer's saved default address (GET /account/addresses), or an empty form.
 * The list comes default first; a failed call or odd data leaves the form empty.
 */
export function addressPrefill(response) {
  const empty = Object.fromEntries(ADDRESS_FIELDS.map((field) => [field, '']));
  const items = Array.isArray(response?.items) ? response.items : [];
  const chosen = items.find((item) => item?.is_default === true) ?? null;
  if (chosen === null) {
    return empty;
  }
  for (const field of ['line1', 'barangay', 'city', 'province', 'postal_code']) {
    empty[field] = text(chosen[field]);
  }
  return empty;
}

/**
 * Splits the server's field errors into those that belong to a form field and the rest.
 * "fulfillment.line1" belongs to the field "line1"; "checkout.<id>" (an extension field) is kept under that same key, which
 * is what checkout.js uses for the field's box; "items", "items.<id>" and any other name are general.
 *
 * @returns {{fields: Record<string, string>, general: string[]}}
 */
export function splitErrors(fields) {
  const result = { fields: {}, general: [] };
  for (const [name, message] of Object.entries(fields ?? {})) {
    if (typeof message !== 'string' || message === '') {
      continue;
    }
    if (name.startsWith('fulfillment.')) {
      result.fields[name.slice('fulfillment.'.length)] = message;
    } else if (/^checkout\.[a-z0-9_]{1,40}$/.test(name) && fieldKey(name.slice('checkout.'.length)) === name) {
      result.fields[name] = message;
    } else if (!result.general.includes(message)) {
      result.general.push(message);
    }
  }
  return result;
}

const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * The server's stock shortage ("Not enough stock for: SKU (only 3 available).") in plain words, using the names of the
 * priced lines in place of SKUs. Returns null when the message is not that one, or no SKU can be matched, so the page
 * falls back to the server's own sentence.
 *
 * @returns {{name: string, available: string}[] | null}
 */
export function shortStockNotes(message, pricedLines) {
  if (typeof message !== 'string' || !message.startsWith('Not enough stock')) {
    return null;
  }
  const notes = [];
  for (const line of pricedLines ?? []) {
    if (typeof line?.sku !== 'string' || line.sku === '') {
      continue;
    }
    const match = new RegExp(`${escapeRegExp(line.sku)} \\(only (\\d+(?:\\.\\d+)?) available\\)`).exec(message);
    if (match !== null) {
      const option = typeof line.variant_name === 'string' && line.variant_name !== '' && line.variant_name !== line.product_name ? ` (${line.variant_name})` : '';
      notes.push({ name: `${line.product_name}${option}`, available: match[1] });
    }
  }
  return notes.length > 0 ? notes : null;
}
