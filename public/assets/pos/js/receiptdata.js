// Turns what the server returned for a sale (GET /pos/sales/{id}) and the terminal snapshot into the text a receipt
// shows. Pure functions, no DOM. The figures are the server's own: the receipt never shows the browser's preview.

import { parseMoney, formatPeso, formatQuantity, parseQuantity } from './money.js';
import { formatDate, formatTime } from './datetime.js';
import { methodLabel } from './tender.js';

const money = (text) => formatPeso(parseMoney(text) ?? 0);

/**
 * @param {object} input
 * @param {object} input.sale the sale as GET /pos/sales/{id} returns it (lines, payments, discounts), plus the optional
 *   `notes` of a line and `footer_notes` of the sale that localsale.js adds from the snapshot's receipt_lines
 * @param {object} input.terminal the snapshot's terminal (code, branch_name, branch_address)
 * @param {string} input.organization the organization's name
 * @param {number} input.vatRatePercent the snapshot's vat_rate_percent
 * @param {string} [input.cashierName] the cashier's name, when it is known to be this sale's cashier
 * @param {{cashTendered: number, change: number}|null} [input.cash] what was handed over and given back, when known
 */
export function receiptData({ sale, terminal, organization, vatRatePercent, cashierName = '', cash = null }) {
  const total = parseMoney(sale.total) ?? 0;
  const vat = parseMoney(sale.vat_amount) ?? 0;
  const discount = sale.discounts?.[0] ?? null;
  const discountAmount = parseMoney(sale.discount_total) ?? 0;

  return {
    organization,
    branchName: terminal.branch_name ?? '',
    branchAddress: terminal.branch_address ?? '',
    terminalCode: terminal.code ?? '',
    receiptNumber: sale.receipt_number,
    date: formatDate(sale.sold_at),
    time: formatTime(sale.sold_at),
    cashier: cashierName,
    lines: sale.lines.map((line) => ({
      name: line.name,
      calc: `${formatQuantity(parseQuantity(line.qty) ?? 0)} \u00D7 ${money(line.unit_price)}`,
      lineTotal: money(line.line_total),
      // Extension lines (Batch 8.3b, receipt-lines.js): present only when there are some, so a receipt without them is unchanged.
      ...(Array.isArray(line.notes) && line.notes.length > 0 ? { notes: line.notes } : {}),
    })),
    subtotal: money(sale.subtotal),
    discount: discountAmount > 0 ? `-${formatPeso(discountAmount)}` : '',
    discountLabel: discount === null ? '' : `Discount: ${discount.label}${discount.id_ref_last4 ? ` (ID ending ${discount.id_ref_last4})` : ''}`,
    total: formatPeso(total),
    vatRate: String(vatRatePercent),
    vatAmount: formatPeso(vat),
    netOfVat: formatPeso(total - vat),
    payments: sale.payments.map((payment) => ({
      label: methodLabel(payment.method),
      amount: money(payment.amount),
      reference: payment.reference ?? '',
    })),
    cashGiven: cash !== null && cash.cashTendered > 0 ? formatPeso(cash.cashTendered) : '',
    change: cash !== null && cash.cashTendered > 0 ? formatPeso(cash.change) : '',
    ...(Array.isArray(sale.footer_notes) && sale.footer_notes.length > 0 ? { footerNotes: sale.footer_notes } : {}),
  };
}
