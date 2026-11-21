import test from 'node:test';
import assert from 'node:assert/strict';
import { settle, quickAmounts, needsReference, methodLabel } from '../../templates/pos/assets/js/tender.js';

test('exact cash completes the sale with no change', () => {
  const s = settle(18350, [{ method: 'cash', amount: 18350 }]);
  assert.equal(s.complete, true);
  assert.equal(s.change, 0);
  assert.deepEqual(s.payments, [{ method: 'cash', amount: 18350, reference: '' }]);
});

test('cash handed over is recorded as the amount applied, and the change is separate', () => {
  const s = settle(18350, [{ method: 'cash', amount: 20000 }]);
  assert.equal(s.complete, true);
  assert.equal(s.change, 1650);
  assert.equal(s.cashTendered, 20000);
  assert.equal(s.payments[0].amount, 18350);
  // What the API receives always adds up to the total exactly.
  assert.equal(s.payments.reduce((sum, p) => sum + p.amount, 0), 18350);
});

test('less than the total is a shortfall and not complete', () => {
  const s = settle(18350, [{ method: 'cash', amount: 10000 }]);
  assert.equal(s.complete, false);
  assert.equal(s.remaining, 8350);
  assert.equal(s.change, 0);
});

test('split tender: a card payment and cash for the rest', () => {
  const s = settle(30000, [
    { method: 'card', amount: 10000, reference: 'AUTH123' },
    { method: 'cash', amount: 25000 },
  ]);
  assert.equal(s.complete, true);
  assert.equal(s.change, 5000);
  assert.deepEqual(s.payments, [
    { method: 'card', amount: 10000, reference: 'AUTH123' },
    { method: 'cash', amount: 20000, reference: '' },
  ]);
  assert.equal(s.payments.reduce((sum, p) => sum + p.amount, 0), 30000);
});

test('the order the payments were entered in does not change the result', () => {
  const a = settle(30000, [{ method: 'cash', amount: 25000 }, { method: 'gcash', amount: 10000, reference: 'G1' }]);
  const b = settle(30000, [{ method: 'gcash', amount: 10000, reference: 'G1' }, { method: 'cash', amount: 25000 }]);
  assert.equal(a.complete, true);
  assert.equal(a.change, b.change);
  assert.deepEqual([...a.payments].sort((x, y) => x.method.localeCompare(y.method)), [...b.payments].sort((x, y) => x.method.localeCompare(y.method)));
});

test('two cash entries are added together', () => {
  const s = settle(15000, [{ method: 'cash', amount: 10000 }, { method: 'cash', amount: 10000 }]);
  assert.equal(s.cashTendered, 20000);
  assert.equal(s.change, 5000);
  assert.equal(s.payments.length, 1);
});

test('only cash can be more than what is due', () => {
  const s = settle(10000, [{ method: 'card', amount: 12000, reference: 'X' }]);
  assert.equal(s.complete, false);
  assert.equal(s.problems[0].field, 'amount');
  const split = settle(10000, [{ method: 'card', amount: 6000, reference: 'X' }, { method: 'maya', amount: 6000, reference: 'Y' }]);
  assert.equal(split.complete, false);
  assert.equal(split.problems.length, 1);
});

test('a method that needs a reference asks for it', () => {
  for (const method of ['gcash', 'maya', 'card', 'bank_transfer']) assert.equal(needsReference(method), true, method);
  for (const method of ['cash', 'cod']) assert.equal(needsReference(method), false, method);

  const s = settle(10000, [{ method: 'gcash', amount: 10000, reference: '  ' }]);
  assert.equal(s.complete, false);
  assert.deepEqual(s.problems.map((p) => p.field), ['reference']);
});

test('an empty or zero payment is not complete', () => {
  assert.equal(settle(10000, []).complete, false);
  assert.equal(settle(10000, [{ method: 'cash', amount: 0 }]).complete, false);
});

test('a zero total cannot be paid, because the server needs a payment above zero', () => {
  const s = settle(0, [{ method: 'cash', amount: 100 }]);
  assert.equal(s.complete, false);
  assert.equal(s.problems[0].field, 'total');
});

test('quick amounts: exact first, then round-ups, smallest first, at most four', () => {
  assert.deepEqual(quickAmounts(18350), [18350, 19000, 20000, 50000, 100000]);
  assert.deepEqual(quickAmounts(20000), [20000, 50000, 100000]);
  assert.deepEqual(quickAmounts(100), [100, 1000, 5000, 10000, 50000]);
  assert.deepEqual(quickAmounts(0), []);
});

test('method labels read well', () => {
  assert.equal(methodLabel('gcash'), 'GCash');
  assert.equal(methodLabel('bank_transfer'), 'Bank transfer');
  assert.equal(methodLabel('new_wallet'), 'New wallet');
});
