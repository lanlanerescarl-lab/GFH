// Extension lines for the receipt (Batch 8.3b, hook pos.receipt.lines). Pure functions, no DOM, no network.
//
// The server puts them in the terminal snapshot as `receipt_lines`:
//   { variants: { "<variant id>": ["Warranty: 12 months"] }, footer: ["Keep this receipt for claims."] }
// (a key is missing when it is empty, and the whole key is missing when no extension registered the hook).
// A sale line prints the lines of its variant under it; the footer lines print once, under the totals.
//
// The receipt takes them from the snapshot the terminal holds when the sale is saved and keeps them with the receipt, so
// a sale made offline and a reprint show exactly what was printed the first time. The server checked every line before
// it went into the snapshot; this file checks again, so a damaged or hand-made snapshot can never put anything but
// short plain text on a receipt. The lines are only ever placed as text, never as markup.

export const MAX_PER_VARIANT = 3;
export const MAX_FOOTER = 3;
export const MAX_LENGTH = 120;

// A control character (line breaks and tabs included) never belongs on one line of a receipt, and neither does anything
// that starts an HTML tag: the server refuses both, and so does this file, as it does for every other limit.
const CONTROL = /[\u0000-\u001F\u007F]/;
const TAG = /<[a-z/!?]/i;

/** The usable lines of one list: text, trimmed, 1 to 120 characters, one line, no repeats, at most `limit` of them. */
export function cleanLines(raw, limit) {
  const lines = [];
  for (const item of Array.isArray(raw) ? raw : []) {
    if (typeof item !== 'string') continue;
    const text = item.trim();
    if (text === '' || [...text].length > MAX_LENGTH || CONTROL.test(text) || TAG.test(text) || lines.includes(text)) continue;
    if (lines.length >= limit) break;
    lines.push(text);
  }
  return lines;
}

/**
 * What the snapshot carries, made safe. Anything that is not the documented shape gives no lines at all.
 *
 * @param {unknown} raw the snapshot's `receipt_lines`
 * @returns {{variants: Map<string, string[]>, footer: string[]}}
 */
export function receiptLinesFrom(raw) {
  const variants = new Map();
  let footer = [];
  if (raw !== null && typeof raw === 'object' && !Array.isArray(raw)) {
    footer = cleanLines(raw.footer, MAX_FOOTER);
    const byVariant = raw.variants;
    if (byVariant !== null && typeof byVariant === 'object' && !Array.isArray(byVariant)) {
      for (const [variantId, lines] of Object.entries(byVariant)) {
        const clean = cleanLines(lines, MAX_PER_VARIANT);
        if (clean.length > 0) variants.set(variantId, clean);
      }
    }
  }
  return { variants, footer };
}

/** The lines to print under a sale line of this variant (an empty list when there are none). */
export const notesForVariant = (lines, variantId) => lines.variants.get(variantId) ?? [];

/** The lines to print once, under the totals. */
export const footerNotes = (lines) => lines.footer;
