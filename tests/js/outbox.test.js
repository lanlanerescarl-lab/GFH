import test from 'node:test';
import assert from 'node:assert/strict';
import { createOutbox } from '../../templates/pos/assets/js/outbox.js';
import { ApiError } from '../../templates/pos/assets/js/api.js';

const sale = { id: 'sale-1', receipt_number: 'T3-000001', lines: [], payments: [] };

test('submit posts the sale at once and returns what the server answered', async () => {
  const sent = [];
  const outbox = createOutbox({ postSale: async (s) => { sent.push(s); return { sale_id: s.id, receipt_number: s.receipt_number, status: 'completed', total: '112.00', duplicate: false }; } });

  const result = await outbox.submit(sale);

  assert.deepEqual(sent, [sale]);
  assert.deepEqual(result, { sale_id: 'sale-1', receipt_number: 'T3-000001', status: 'completed', total: '112.00', duplicate: false });
});

test('nothing waits in this batch: pendingCount is a number and is always 0', async () => {
  const outbox = createOutbox({ postSale: async () => ({ duplicate: false }) });
  assert.equal(outbox.pendingCount(), 0);
  await outbox.submit(sale);
  assert.equal(outbox.pendingCount(), 0);
});

test('a failed send throws the error, queues nothing, and leaves the sale for a retry', async () => {
  let attempt = 0;
  const outbox = createOutbox({
    postSale: async (s) => {
      attempt += 1;
      if (attempt === 1) throw new ApiError({ network: true, message: 'Cannot reach the server.' });
      return { sale_id: s.id, duplicate: false };
    },
  });

  await assert.rejects(() => outbox.submit(sale), (error) => error.network === true);
  assert.equal(outbox.pendingCount(), 0, 'a failed sale is not hidden in a queue; it stays on screen');

  const retry = await outbox.submit(sale);
  assert.equal(retry.sale_id, 'sale-1');
});

test('a retry sends the same sale id, which is what makes it safe', async () => {
  const ids = [];
  const outbox = createOutbox({ postSale: async (s) => { ids.push(s.id); return { sale_id: s.id, duplicate: ids.length > 1 }; } });

  const first = await outbox.submit(sale);
  const second = await outbox.submit(sale);

  assert.deepEqual(ids, ['sale-1', 'sale-1']);
  assert.equal(first.duplicate, false);
  assert.equal(second.duplicate, true);
});

test('the outbox offers exactly the two things the screens use', () => {
  const outbox = createOutbox({ postSale: async () => ({}) });
  assert.deepEqual(Object.keys(outbox).sort(), ['pendingCount', 'submit']);
});
