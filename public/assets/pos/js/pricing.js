// Preview pricing for the POS. It mirrors core/Service/PricingService.php so the cashier sees the total the server
// will work out. The server's recomputed total is the authority: when it differs, the receipt shows the server's figures.
//
// Rules (same as the server):
//  - prices are VAT-inclusive centavos; VAT is 12% and is extracted: net = round(total / 1.12), VAT = total - net;
//  - a quantity tier applies from its minimum quantity; the lowest matching price wins and is never above retail;
//  - a plain discount takes its percent off the VAT-inclusive subtotal;
//  - a VAT-exempt discount (senior, PWD) removes the VAT first, then takes the percent of the VAT-free amount;
//  - rounding is half away from zero, done in whole numbers (BigInt inside, so a large cart cannot lose precision).
//
// Pure functions: no DOM, no network, no floating point.

import { parseMoney, parsePercent, parseQuantity } from './money.js';

export const VAT_RATE_PERCENT = 12;
const MAX_INT64 = (1n << 63n) - 1n;

/** Round-half-away-from-zero of a * b / divisor. Throws RangeError when the amount is too large, like the server. */
export function mulDiv(a, b, divisor) {
  if (!Number.isInteger(a) || !Number.isInteger(b) || !Number.isInteger(divisor) || divisor <= 0) {
    throw new RangeError('The divisor must be positive and every amount a whole number.');
  }
  const product = BigInt(a) * BigInt(b);
  if (product > MAX_INT64 || product < -MAX_INT64) throw new RangeError('The amount is too large to calculate.');

  const abs = product < 0n ? -product : product;
  const quotient = (2n * abs + BigInt(divisor)) / (2n * BigInt(divisor));
  const result = product < 0n ? -quotient : quotient;
  if (result > BigInt(Number.MAX_SAFE_INTEGER) || result < -BigInt(Number.MAX_SAFE_INTEGER)) {
    throw new RangeError('The amount is too large to calculate.');
  }

  return Number(result);
}

/** Splits a VAT-inclusive total into its VAT-free amount and the VAT, in centavos. */
export function extractVat(totalCentavos) {
  const net = mulDiv(totalCentavos, 100, 100 + VAT_RATE_PERCENT);
  return { net, vat: totalCentavos - net };
}

/** The unit price for a quantity: retail, or the lowest matching quantity-tier price. Centavos and the tier's label. */
export function bestPrice(product, quantityMilli) {
  let best = parseMoney(product.price) ?? 0;
  let label = null;

  for (const tier of product.tiers ?? []) {
    const minimum = parseQuantity(tier.min_qty) ?? 1000;
    const price = parseMoney(tier.price);
    if (price === null || quantityMilli < minimum) continue;
    if (price < best) {
      best = price;
      label = tier.label ?? null;
    }
  }

  return { price: best, label };
}

/** Applies a discount rule ({percent, vat_exempt}) to a VAT-inclusive subtotal. total = subtotal - discountTotal. */
export function applyDiscount(subtotal, rule) {
  const percent = parsePercent(rule.percent) ?? 0; // "20.00" is 2000

  if (rule.vat_exempt) {
    const net = extractVat(subtotal).net;
    const total = net - mulDiv(net, percent, 10000);
    return { discountTotal: subtotal - total, total, vatAmount: 0 };
  }

  const discountTotal = mulDiv(subtotal, percent, 10000);
  const total = subtotal - discountTotal;
  return { discountTotal, total, vatAmount: extractVat(total).vat };
}

/**
 * Prices a ticket.
 *
 * @param {Array<{product: object, quantityMilli: number}>} entries one per line (the ticket already merges a product into one line)
 * @param {object|null} rule the chosen discount rule, or null
 * @returns {{lines: Array, subtotal: number, discountTotal: number, total: number, vatAmount: number, netOfVat: number}}
 */
export function priceSale(entries, rule = null) {
  const lines = [];
  let subtotal = 0;

  for (const { product, quantityMilli } of entries) {
    const { price, label } = bestPrice(product, quantityMilli);
    const lineTotal = mulDiv(quantityMilli, price, 1000);
    subtotal += lineTotal;
    lines.push({ variantId: product.variant_id, quantityMilli, unitPrice: price, tierLabel: label, lineTotal });
  }

  if (rule === null || rule === undefined) {
    const vatAmount = extractVat(subtotal).vat;
    return { lines, subtotal, discountTotal: 0, total: subtotal, vatAmount, netOfVat: subtotal - vatAmount };
  }

  const applied = applyDiscount(subtotal, rule);
  return {
    lines, subtotal, discountTotal: applied.discountTotal, total: applied.total,
    vatAmount: applied.vatAmount, netOfVat: applied.total - applied.vatAmount,
  };
}
