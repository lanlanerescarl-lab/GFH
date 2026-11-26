// Batch 8.3b: extension lines on the POS receipt (hook pos.receipt.lines). The lines come from the terminal snapshot's
// `receipt_lines`, are copied onto the receipt when the sale is saved, and stay with the stored receipt for reprints.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { priceSale } from '../../templates/pos/assets/js/pricing.js';
import { buildSale } from '../../templates/pos/assets/js/sale.js';
import { serverShapedSale, receiptBuilder } from '../../templates/pos/assets/js/localsale.js';
import { receiptData } from '../../templates/pos/assets/js/receiptdata.js';
import { createOutbox } from '../../templates/pos/assets/js/outbox.js';
import { createMemoryStore } from '../../templates/pos/assets/js/store.js';
import { indexProducts } from '../../templates/pos/assets/js/catalog.js';
import { toApiMoney } from '../../templates/pos/assets/js/money.js';
import {
  MAX_FOOTER, MAX_LENGTH, MAX_PER_VARIANT, cleanLines, footerNotes, notesForVariant, receiptLinesFrom,
} from '../../templates/pos/assets/js/receipt-lines.js';
import { snapshotProduct, openShiftRecord, fakeServer } from './support.js';

const fridge = snapshotProduct('v1', { price: '18000.00', decimals: false, step: '1.000', min: '1.000', tiers: [] });
const cable = snapshotProduct('v2', { price: '150.00', decimals: false, step: '1.000', min: '1.000', tiers: [] });
const productsByVariant = indexProducts([fridge, cable]);
const terminal = { code: 'T3', branch_name: 'Bacolod Main', branch_address: '12 Lacson St' };

const SNAPSHOT_LINES = { variants: { v1: ['Warranty: 12 months'] }, footer: ['Keep this receipt for warranty claims.'] };

/** One fridge and one cable on the same sale. */
function ring() {
  const priced = priceSale([{ product: fridge, quantityMilli: 1000 }, { product: cable, quantityMilli: 2000 }], null);
  const sale = buildSale({
    id: 'sale-1', receiptNumber: null, terminalId: 'term-1', shiftId: 'shift-1', soldAt: '2026-11-23T08:00:00.000Z',
    lines: priced.lines, payments: [{ method: 'cash', amount: toApiMoney(priced.total), reference: null }], rule: null, idLast4: null,
  });
  return { priced, sale };
}

const builder = (receiptLines) => {
  const { priced, sale } = ring();
  return { sale, priced, build: receiptBuilder({ sale, priced, productsByVariant, rule: null, idLast4: null, terminal, organization: 'Example Store', vatRatePercent: 12, cashierName: 'Casey Cashier', cash: null, receiptLines }) };
};

// ------------------------------------------------------------------ the module

test('a line is kept only when it is short plain text on one line', () => {
  assert.deepEqual(cleanLines(['  Warranty: 12 months  '], 3), ['Warranty: 12 months']);
  assert.deepEqual(cleanLines(['x'.repeat(MAX_LENGTH)], 3), ['x'.repeat(MAX_LENGTH)]);
  assert.deepEqual(cleanLines(['\u00E9'.repeat(MAX_LENGTH)], 3).length, 1, '120 characters, however many bytes');
  assert.deepEqual(cleanLines(['\u{1F4A1}'.repeat(MAX_LENGTH)], 3).length, 1, 'a character outside the basic plane counts once');
  for (const bad of ['', '   ', 'x'.repeat(MAX_LENGTH + 1), 'one\ntwo', 'one\rtwo', 'tab\there', 'nul\u0000byte', 'esc\u001B[31m', 'Parts <b>only</b>', '<script>alert(1)</script>', 'x </p>', 'x <!-- y -->', 5, null, undefined, {}, ['a']]) {
    assert.deepEqual(cleanLines([bad], 3), [], `refused: ${JSON.stringify(bad)}`);
  }
  assert.deepEqual(cleanLines(['3 < 5 & "quoted"'], 3), ['3 < 5 & "quoted"'], 'a less-than sign that starts no tag is only text');
});

test('at most three lines, no repeats, whatever the list holds', () => {
  assert.equal(MAX_PER_VARIANT, 3);
  assert.equal(MAX_FOOTER, 3);
  assert.deepEqual(cleanLines(['a', 'b', 'a', 'c', 'd', 'e'], 3), ['a', 'b', 'c']);
  assert.deepEqual(cleanLines('not a list', 3), []);
  assert.deepEqual(cleanLines(undefined, 3), []);
});

test('the snapshot value is read defensively: anything that is not the documented shape gives no lines', () => {
  for (const nothing of [undefined, null, 'x', 5, [], [['a']], {}, { variants: [], footer: 'x' }, { variants: 'v1', footer: null }, { variants: { v1: 'Warranty' } }]) {
    const lines = receiptLinesFrom(nothing);
    assert.equal(lines.variants.size, 0, JSON.stringify(nothing));
    assert.deepEqual(lines.footer, []);
  }
  const lines = receiptLinesFrom({ variants: { v1: ['a', 'b', 'c', 'd'], v2: ['<i>x</i>'], v3: [] }, footer: ['f1', 'f2', 'f3', 'f4'] });
  assert.deepEqual(notesForVariant(lines, 'v1'), ['a', 'b', 'c']);
  assert.deepEqual(notesForVariant(lines, 'v2'), [], 'a line with a tag is refused here too');
  assert.deepEqual(notesForVariant(lines, 'v3'), []);
  assert.deepEqual(notesForVariant(lines, 'unknown'), []);
  assert.deepEqual(footerNotes(lines), ['f1', 'f2', 'f3']);
});

// ------------------------------------------------------------------ nothing registered: nothing changes

test('without receipt_lines the sale and the receipt data are exactly what they were', () => {
  for (const nothing of [undefined, null, {}, { variants: {}, footer: [] }, 'junk']) {
    const { priced, sale } = ring();
    const shaped = serverShapedSale({ sale: { ...sale, receipt_number: 'T3-000123' }, priced, productsByVariant, receiptLines: nothing });
    const before = serverShapedSale({ sale: { ...sale, receipt_number: 'T3-000123' }, priced, productsByVariant });

    assert.deepEqual(shaped, before);
    assert.equal('footer_notes' in shaped, false);
    for (const line of shaped.lines) assert.deepEqual(Object.keys(line), ['name', 'qty', 'unit_price', 'line_total']);

    const data = receiptData({ sale: shaped, terminal, organization: 'Example Store', vatRatePercent: 12, cashierName: 'Casey', cash: null });
    assert.equal('footerNotes' in data, false);
    for (const line of data.lines) assert.deepEqual(Object.keys(line), ['name', 'calc', 'lineTotal']);
  }
});

// ------------------------------------------------------------------ with lines

test('a warranty line prints under the sale line of its product only, and the footer line once', () => {
  const { priced, sale } = ring();
  const shaped = serverShapedSale({ sale: { ...sale, receipt_number: 'T3-000123' }, priced, productsByVariant, receiptLines: SNAPSHOT_LINES });

  assert.deepEqual(shaped.lines[0].notes, ['Warranty: 12 months']);
  assert.equal('notes' in shaped.lines[1], false, 'the cable has no warranty');
  assert.deepEqual(shaped.footer_notes, ['Keep this receipt for warranty claims.']);

  const data = receiptData({ sale: shaped, terminal, organization: 'Example Store', vatRatePercent: 12, cashierName: 'Casey', cash: null });
  assert.deepEqual(data.lines[0].notes, ['Warranty: 12 months']);
  assert.equal('notes' in data.lines[1], false);
  assert.deepEqual(data.footerNotes, ['Keep this receipt for warranty claims.']);
  // Everything else on the receipt is the same as without the lines.
  const plain = receiptData({ sale: serverShapedSale({ sale: { ...sale, receipt_number: 'T3-000123' }, priced, productsByVariant }), terminal, organization: 'Example Store', vatRatePercent: 12, cashierName: 'Casey', cash: null });
  const { footerNotes: _footer, ...rest } = data;
  assert.deepEqual({ ...rest, lines: rest.lines.map(({ notes: _notes, ...line }) => line) }, plain);
});

test('two sale lines of the same product both carry its lines', () => {
  const priced = priceSale([{ product: fridge, quantityMilli: 1000 }, { product: cable, quantityMilli: 1000 }, { product: fridge, quantityMilli: 1000 }], null);
  const sale = buildSale({ id: 's', receiptNumber: null, terminalId: 't', shiftId: 'sh', soldAt: '2026-11-23T08:00:00.000Z', lines: priced.lines, payments: [], rule: null, idLast4: null });
  const shaped = serverShapedSale({ sale: { ...sale, receipt_number: 'T3-1' }, priced, productsByVariant, receiptLines: SNAPSHOT_LINES });

  assert.deepEqual(shaped.lines.map((line) => line.notes ?? null), [['Warranty: 12 months'], null, ['Warranty: 12 months']]);
});

test('a line that no longer obeys the rules never reaches the receipt, even from a damaged snapshot', () => {
  const { priced, sale } = ring();
  const hostile = { variants: { v1: ['<img src=x onerror=alert(1)>', 'ok line', 'two\nlines', 'x'.repeat(200)] }, footer: ['<b>bold</b>'] };
  const shaped = serverShapedSale({ sale: { ...sale, receipt_number: 'T3-1' }, priced, productsByVariant, receiptLines: hostile });

  assert.deepEqual(shaped.lines[0].notes, ['ok line']);
  assert.equal('footer_notes' in shaped, false);
});

// ------------------------------------------------------------------ offline, reprint, older snapshot

test('a sale made offline prints the lines, and the stored receipt keeps them for a reprint before and after it is sent', async () => {
  const { sale, build } = builder(SNAPSHOT_LINES);

  const store = createMemoryStore();
  await openShiftRecord(store);
  const server = fakeServer();
  const outbox = createOutbox({ api: server, store, isOnline: () => false });
  const result = await outbox.submit(sale, { buildReceipt: build, cash: null });

  const printed = build(result.receipt_number);
  assert.deepEqual(printed.lines[0].notes, ['Warranty: 12 months']);
  assert.deepEqual(printed.footerNotes, ['Keep this receipt for warranty claims.']);

  const queued = (await outbox.shiftSales('shift-1'))[0];
  assert.deepEqual(queued.receipt, printed, 'what is stored is what was printed, lines included');

  await outbox.sync({ force: true });
  const sent = (await outbox.shiftSales('shift-1'))[0];
  assert.equal(sent.state, 'synced');
  assert.deepEqual(sent.receipt, printed, 'the reprint survives the sale leaving the queue');
});

test('the sale that goes to the server is the same with or without receipt lines: the queue payload is untouched', async () => {
  const posted = [];
  for (const lines of [null, SNAPSHOT_LINES]) {
    const { sale, build } = builder(lines);
    const store = createMemoryStore();
    await openShiftRecord(store);
    const server = fakeServer();
    const api = { ...server, postSale: async (payload) => { posted.push(JSON.stringify(payload)); return server.postSale(payload); } };
    const outbox = createOutbox({ api, store, isOnline: () => true });
    await outbox.submit(sale, { buildReceipt: build, cash: null });
    await outbox.sync({ force: true });
  }

  assert.equal(posted.length, 2, 'both sales were uploaded');
  assert.equal(posted[0], posted[1], 'byte for byte the same request body');
  assert.doesNotMatch(posted[1], /Warranty|footer_notes|receipt_lines|notes/);
});

test('a receipt shows the lines of the snapshot the terminal held when the sale was saved, and a reprint never changes', async () => {
  const older = { variants: { v1: ['Warranty: 6 months'] } };
  const newer = { variants: { v1: ['Warranty: 12 months'] } };

  const first = builder(older);
  const store = createMemoryStore();
  await openShiftRecord(store);
  const outbox = createOutbox({ api: fakeServer(), store, isOnline: () => false });
  const result = await outbox.submit(first.sale, { buildReceipt: first.build, cash: null });

  // The server's warranty data changed to 12 months; this terminal has not downloaded a newer snapshot yet, so the next sale still says 6.
  assert.deepEqual(first.build(result.receipt_number).lines[0].notes, ['Warranty: 6 months']);
  // Once the terminal has the newer snapshot, a new sale says 12 ...
  assert.deepEqual(builder(newer).build('T3-000124').lines[0].notes, ['Warranty: 12 months']);
  // ... and the receipt already printed (and its reprint) still says what was printed.
  assert.deepEqual((await outbox.shiftSales('shift-1'))[0].receipt.lines[0].notes, ['Warranty: 6 months']);
});

// ------------------------------------------------------------------ text only

test('the modules that carry the lines never build markup from them', () => {
  for (const file of ['receipt-lines.js', 'receipt.js', 'receiptdata.js', 'localsale.js']) {
    const source = readFileSync(new URL(`../../templates/pos/assets/js/${file}`, import.meta.url), 'utf8');
    assert.doesNotMatch(source, /innerHTML|outerHTML|insertAdjacentHTML|document\.write|createContextualFragment|eval\(/, file);
  }
});
