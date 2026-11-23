// Draws the server's priced cart. Every number and every word shown here came from the server's answer; nothing is
// worked out in the browser. Used by the cart page and by the checkout summary.
import { el } from './dom.js';
import { formatDate, formatPeso, formatQuantity, isPositiveAmount } from './format.js';

function row(label, value, className = '') {
  return el('div', { class: `summary__row ${className}`.trim() }, el('dt', { text: label }), el('dd', { text: value }));
}

/**
 * The totals block: subtotal, discount, fees (only when there is one), the VAT-included note, the total, and the
 * deposit and promised date when the server returns them.
 */
export function renderSummary(target, data, { showDeposit = false } = {}) {
  const list = el('dl', { class: 'summary' });
  list.append(row('Subtotal', formatPeso(data.subtotal)));
  if (isPositiveAmount(data.discount_total)) {
    const label = data.discount && typeof data.discount.label === 'string' && data.discount.label !== '' ? `Discount (${data.discount.label})` : 'Discount';
    list.append(row(label, `-${formatPeso(data.discount_total)}`));
  }
  if (isPositiveAmount(data.delivery_fee)) {
    list.append(row('Delivery fee', formatPeso(data.delivery_fee)));
  }
  if (isPositiveAmount(data.assembly_fee)) {
    list.append(row('Assembly fee', formatPeso(data.assembly_fee)));
  }
  if (isPositiveAmount(data.installation_fee)) {
    list.append(row('Installation fee', formatPeso(data.installation_fee)));
  }
  list.append(row('Total', formatPeso(data.total), 'summary__row--total'));

  const children = [list, el('p', { class: 'help summary__vat', text: `Prices include VAT. VAT included in the total: ${formatPeso(data.vat_amount)}.` })];
  if (showDeposit && isPositiveAmount(data.deposit_due)) {
    children.push(el('p', { class: 'summary__deposit', text: `Deposit due: ${formatPeso(data.deposit_due)}.` }));
  }
  if (showDeposit && typeof data.promised_date === 'string' && data.promised_date !== '') {
    children.push(el('p', { class: 'summary__promised', text: `Promised date: ${formatDate(data.promised_date)}.` }));
  }
  target.replaceChildren(...children);
}

/** "Product name" and, when it adds something, the option label. */
export function lineTitle(line) {
  const option = typeof line.variant_name === 'string' ? line.variant_name : '';
  return option !== '' && option !== line.product_name ? `${line.product_name} (${option})` : String(line.product_name);
}

/** The "₱12.00 per kg, price for ..." line under a product name. */
export function unitPriceText(line) {
  const unit = typeof line.unit === 'string' && line.unit !== '' ? ` per ${line.unit}` : '';
  const tier = typeof line.tier_applied === 'string' && line.tier_applied !== '' ? ` · ${line.tier_applied} price` : '';
  return `${formatPeso(line.unit_price)}${unit}${tier}`;
}

/** A read-only list of lines (checkout summary). */
export function renderLineList(target, lines) {
  const list = el('ul', { class: 'cart-lines cart-lines--compact' });
  for (const line of lines) {
    list.append(el('li', { class: 'cart-line cart-line--compact' },
      el('div', { class: 'cart-line__info' },
        el('p', { class: 'cart-line__name', text: lineTitle(line) }),
        el('p', { class: 'cart-line__price', text: `${formatQuantity(line.quantity)} × ${unitPriceText(line)}` }),
        line.is_made_to_order ? el('p', { class: 'cart-line__flag', text: 'Made to order' }) : null),
      el('p', { class: 'cart-line__total', text: formatPeso(line.line_total) })));
  }
  target.replaceChildren(list);
}
