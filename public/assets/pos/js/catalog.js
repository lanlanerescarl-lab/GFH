// Looking things up in the terminal snapshot: search and stock badges. Pure functions, no DOM.

import { parseQuantity, formatQuantity } from './money.js';

/** At or below this much on hand (5, in thousandths) a product is shown as low on stock. The snapshot carries no reorder level. */
export const LOW_STOCK_MILLI = 5000;

/** The snapshot's products by variant id, for quick lookup. */
export function indexProducts(products) {
  return new Map(products.map((product) => [product.variant_id, product]));
}

/** Products whose name, SKU or barcode contain every word of the query (case-insensitive). An empty query lists everything. */
export function searchProducts(products, query) {
  const words = String(query ?? '').toLowerCase().split(/\s+/).filter((word) => word !== '');
  if (words.length === 0) return products;

  return products.filter((product) => {
    const haystack = `${product.name} ${product.sku} ${product.barcode ?? ''}`.toLowerCase();
    return words.every((word) => haystack.includes(word));
  });
}

/** "out", "low" or "ok", from what the snapshot says is on hand. It only informs: a sale is never blocked for stock. */
export function stockStatus(product) {
  const onHand = parseQuantity(String(product.on_hand ?? '0').replace(/^-/, '')) ?? 0;
  const negative = String(product.on_hand ?? '').startsWith('-');
  if (negative || onHand <= 0) return 'out';
  return onHand <= LOW_STOCK_MILLI ? 'low' : 'ok';
}

/** A note for a ticket line that asks for more than the snapshot says is on hand, or null. The sale still goes through. */
export function oversellNote(product, quantityMilli) {
  const text = String(product.on_hand ?? '0');
  const onHand = text.startsWith('-') ? 0 : (parseQuantity(text) ?? 0);
  if (quantityMilli <= onHand) return null;
  return onHand === 0
    ? 'None in stock on record. The sale can still go ahead and is flagged for review.'
    : `Only ${formatQuantity(onHand)} in stock on record. The sale can still go ahead and is flagged for review.`;
}
