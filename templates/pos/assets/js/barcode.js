// Barcode matching. A scanner types the digits and Enter, so the search box hands its text here. Pure functions, no DOM.
//
// A barcode is text, never a number: "00123" and "123" are different codes, so leading zeros are kept and nothing is
// converted to a number. One equivalence is allowed because scanners really do disagree about it: a 12-digit UPC-A and
// the 13-digit EAN-13 that starts with 0 and then the same 12 digits are the same product.

/** What the scanner typed, without surrounding spaces or line breaks. */
export function normalizeScan(text) {
  return String(text ?? '').trim();
}

function sameGtin(a, b) {
  const digits = /^\d+$/;
  if (!digits.test(a) || !digits.test(b)) return false;
  const [short, long] = a.length <= b.length ? [a, b] : [b, a];
  return short.length === 12 && long.length === 13 && long === `0${short}`;
}

/** The product whose barcode is exactly this text, or null. An exact match always wins over the UPC/EAN equivalence. */
export function findByBarcode(products, scanned) {
  const code = normalizeScan(scanned);
  if (code === '') return null;

  const exact = products.find((product) => product.barcode !== null && product.barcode !== undefined && product.barcode === code);
  if (exact !== undefined) return exact;

  return products.find((product) => typeof product.barcode === 'string' && sameGtin(product.barcode, code)) ?? null;
}
