import test from 'node:test';
import assert from 'node:assert/strict';
import { priceSale } from '../../templates/pos/assets/js/pricing.js';
import { buildSale } from '../../templates/pos/assets/js/sale.js';
import { serverShapedSale, receiptBuilder } from '../../templates/pos/assets/js/localsale.js';
import { createOutbox } from '../../templates/pos/assets/js/outbox.js';
import { createMemoryStore } from '../../templates/pos/assets/js/store.js';
import { indexProducts } from '../../templates/pos/assets/js/catalog.js';
import { toApiMoney } from '../../templates/pos/assets/js/money.js';
import { snapshotProduct, openShiftRecord, fakeServer } from './support.js';

const product = snapshotProduct('v1', { price: '112.00', decimals: false, step: '1.000', min: '1.000', tiers: [] });
const productsByVariant = indexProducts([product]);
const terminal = { code: 'T3', branch_name: 'Bacolod Main', branch_address: '12 Lacson St' };

function ring(quantityMilli = 2000, rule = null) {
  const priced = priceSale([{ product, quantityMilli }], rule);
  const sale = buildSale({
    id: 'sale-1', receiptNumber: null, terminalId: 'term-1', shiftId: 'shift-1', soldAt: '2026-11-23T08:00:00.000Z',
    lines: priced.lines, payments: [{ method: 'cash', amount: toApiMoney(priced.total), reference: null }], rule, idLast4: null,
  });
  return { priced, sale };
}

test('the local receipt reads like the server one: lines, totals, VAT, payments, number and the cashier', () => {
  const { priced, sale } = ring();
  const shaped = serverShapedSale({ sale: { ...sale, receipt_number: 'T3-000123' }, priced, productsByVariant });

  assert.equal(shaped.receipt_number, 'T3-000123');
  assert.deepEqual(shaped.lines, [{ name: 'Product v1', qty: '2.000', unit_price: '112.00', line_total: '224.00' }]);
  assert.deepEqual([shaped.subtotal, shaped.discount_total, shaped.total], ['224.00', '0.00', '224.00']);
  assert.equal(shaped.vat_amount, toApiMoney(priced.vatAmount));
  assert.deepEqual(shaped.payments, sale.payments);
});

test('a sale made offline prints its receipt with the number it took, and the same receipt can be reprinted from the store', async () => {
  const { priced, sale } = ring();
  const build = receiptBuilder({ sale, priced, productsByVariant, rule: null, idLast4: null, terminal, organization: 'Example Store', vatRatePercent: 12, cashierName: 'Casey Cashier', cash: { cashTendered: 30000, change: 7600 } });

  const store = createMemoryStore();
  await openShiftRecord(store);
  const server = fakeServer();
  const outbox = createOutbox({ api: server, store, isOnline: () => false });
  const result = await outbox.submit(sale, { buildReceipt: build, cash: { cashTendered: 30000, change: 7600 } });

  const printed = build(result.receipt_number);
  assert.equal(printed.receiptNumber, 'T3-000123');
  assert.equal(printed.cashier, 'Casey Cashier');
  assert.equal(printed.total, '\u20B1224.00');
  assert.equal(printed.organization, 'Example Store');

  const queued = (await outbox.shiftSales('shift-1'))[0];
  assert.deepEqual(queued.receipt, printed, 'what is stored is what was printed');
  assert.equal(queued.state, 'queued');

  await outbox.sync({ force: true });
  const sent = (await outbox.shiftSales('shift-1'))[0];
  assert.equal(sent.state, 'synced');
  assert.deepEqual(sent.receipt, printed, 'the reprint survives the sale leaving the queue');
});

test('a discount shows on the local receipt with its rule label and the last four digits of the ID', () => {
  const rule = { key: 'senior', label: 'Senior citizen', percent: '20.00', vat_exempt: true };
  const { priced, sale } = ring(1000, rule);
  const shaped = serverShapedSale({ sale: { ...sale, receipt_number: 'T3-000124' }, priced, productsByVariant, rule, idLast4: 'AB12' });
  assert.deepEqual(shaped.discounts, [{ label: 'Senior citizen', id_ref_last4: 'AB12' }]);
  assert.notEqual(shaped.discount_total, '0.00');
});
