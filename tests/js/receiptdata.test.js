import test from 'node:test';
import assert from 'node:assert/strict';
import { receiptData } from '../../templates/pos/assets/js/receiptdata.js';

const terminal = { code: 'T3', branch_name: 'Bacolod Main', branch_address: '12 Lacson St, Bacolod City, Negros Occidental' };
const sale = {
  receipt_number: 'T3-000007', sold_at: '2026-11-20 14:05:09+08', cashier_user_id: 'u1',
  subtotal: '360.50', discount_total: '103.00', vat_amount: '0.00', total: '257.50', status: 'completed',
  lines: [{ name: 'Coffee 250 g', qty: '2.000', unit_price: '112.00', line_total: '224.00' }, { name: 'Rice - kilo', qty: '1.500', unit_price: '91.00', line_total: '136.50' }],
  payments: [{ method: 'card', amount: '100.00', reference: 'AUTH77' }, { method: 'cash', amount: '157.50', reference: null }],
  discounts: [{ amount: '103.00', id_ref_last4: 'AB12', key: 'senior', label: 'Senior citizen' }],
};

test('a receipt shows the server figures as pesos, with the date and time in Manila', () => {
  const data = receiptData({ sale, terminal, organization: 'Example Store', vatRatePercent: 12, cashierName: 'Casey Cashier', cash: { cashTendered: 20000, change: 4250 } });

  assert.equal(data.organization, 'Example Store');
  assert.equal(data.branchName, 'Bacolod Main');
  assert.equal(data.branchAddress, '12 Lacson St, Bacolod City, Negros Occidental');
  assert.equal(data.terminalCode, 'T3');
  assert.equal(data.receiptNumber, 'T3-000007');
  assert.equal(data.date, '20 Nov 2026');
  assert.equal(data.time, '2:05 PM');
  assert.equal(data.cashier, 'Casey Cashier');
  assert.deepEqual(data.lines, [
    { name: 'Coffee 250 g', calc: '2 \u00D7 \u20B1112.00', lineTotal: '\u20B1224.00' },
    { name: 'Rice - kilo', calc: '1.5 \u00D7 \u20B191.00', lineTotal: '\u20B1136.50' },
  ]);
  assert.equal(data.subtotal, '\u20B1360.50');
  assert.equal(data.discount, '-\u20B1103.00');
  assert.equal(data.discountLabel, 'Discount: Senior citizen (ID ending AB12)');
  assert.equal(data.total, '\u20B1257.50');
  assert.equal(data.vatAmount, '\u20B10.00');
  assert.equal(data.netOfVat, '\u20B1257.50');
  assert.equal(data.vatRate, '12');
  assert.deepEqual(data.payments, [
    { label: 'Card', amount: '\u20B1100.00', reference: 'AUTH77' },
    { label: 'Cash', amount: '\u20B1157.50', reference: '' },
  ]);
  assert.equal(data.cashGiven, '\u20B1200.00');
  assert.equal(data.change, '\u20B142.50');
});

test('the VAT breakdown is the one the server returned, not a recalculation', () => {
  const data = receiptData({ sale: { ...sale, discount_total: '0.00', discounts: [], vat_amount: '27.59', total: '257.50' }, terminal, organization: 'X', vatRatePercent: 12 });
  assert.equal(data.vatAmount, '\u20B127.59');
  assert.equal(data.netOfVat, '\u20B1229.91');
  assert.equal(data.discount, '');
  assert.equal(data.discountLabel, '');
});

test('without a known cash tender there is no cash given and no change, and no cashier name is invented', () => {
  const data = receiptData({ sale, terminal, organization: 'X', vatRatePercent: 12 });
  assert.equal(data.cashGiven, '');
  assert.equal(data.change, '');
  assert.equal(data.cashier, '');
});

test('an exact cash payment shows the cash given and zero change', () => {
  const data = receiptData({ sale, terminal, organization: 'X', vatRatePercent: 12, cash: { cashTendered: 15750, change: 0 } });
  assert.equal(data.change, '\u20B10.00');
});
