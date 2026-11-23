// Node tests for the Shop's API client (templates/shop/assets/js/api.js): the envelope, error mapping, the CSRF header.
// Run: node --test tests/js
import assert from 'node:assert/strict';
import test from 'node:test';

import { ApiError, createApi, fieldErrors, readEnvelope } from '../../templates/shop/assets/js/api.js';

/** A fake fetch that answers from a queue and records every call. */
function fakeFetch(...answers) {
  const calls = [];
  const fetchImpl = async (url, init) => {
    calls.push({ url, init });
    const answer = answers.shift();
    if (answer instanceof Error) {
      throw answer;
    }
    return answer;
  };
  return { fetchImpl, calls };
}

const reply = (body, status = 200, headers = {}) => ({
  ok: status >= 200 && status < 300,
  status,
  headers: { get: (name) => headers[name] ?? null },
  json: async () => {
    if (body === undefined) {
      throw new SyntaxError('not JSON');
    }
    return body;
  },
});

test('a success envelope gives its data', async () => {
  const { fetchImpl, calls } = fakeFetch(reply({ ok: true, data: { total: '224.00' } }));
  const api = createApi({ baseUrl: '/demo/api/v1', csrfToken: 't', fetchImpl });
  assert.deepEqual(await api.get('/checkout/options'), { total: '224.00' });
  assert.equal(calls[0].url, '/demo/api/v1/checkout/options');
  assert.equal(calls[0].init.method, 'GET');
  assert.equal(calls[0].init.credentials, 'same-origin');
});

test('a GET sends no CSRF header and no body; a POST sends the header and a JSON body', async () => {
  const { fetchImpl, calls } = fakeFetch(reply({ ok: true, data: {} }), reply({ ok: true, data: {} }));
  const api = createApi({ baseUrl: '/demo/api/v1', csrfToken: 'tok-123', fetchImpl });
  await api.get('/x');
  await api.post('/cart/price', { items: [] });
  assert.equal(calls[0].init.headers['X-CSRF-Token'], undefined);
  assert.equal(calls[0].init.body, undefined);
  assert.equal(calls[1].init.headers['X-CSRF-Token'], 'tok-123');
  assert.equal(calls[1].init.headers['Content-Type'], 'application/json');
  assert.deepEqual(JSON.parse(calls[1].init.body), { items: [] });
});

test('a 422 becomes an ApiError with a message per field', async () => {
  const body = { ok: false, error: { code: 'validation_failed', message: 'Please fix the highlighted fields.', fields: { 'fulfillment.city': 'This field is required.', items: 'Not enough stock for: A (only 1 available).' } } };
  const { fetchImpl } = fakeFetch(reply(body, 422));
  const api = createApi({ baseUrl: '/api', csrfToken: 't', fetchImpl });

  await assert.rejects(api.post('/checkout', {}), (error) => {
    assert.ok(error instanceof ApiError);
    assert.equal(error.status, 422);
    assert.equal(error.isValidation, true);
    assert.equal(error.isNetwork, false);
    assert.equal(error.code, 'validation_failed');
    assert.equal(error.message, 'Please fix the highlighted fields.');
    assert.deepEqual(fieldErrors(error), { 'fulfillment.city': 'This field is required.', items: 'Not enough stock for: A (only 1 available).' });
    return true;
  });
});

test('field errors keep only plain text and tolerate nothing at all', () => {
  const error = new ApiError({ status: 422, code: 'x', message: 'm', fields: { a: 'text', b: '', c: 5, d: null, e: { nested: 1 } } });
  assert.deepEqual(fieldErrors(error), { a: 'text' });
  assert.deepEqual(fieldErrors(new Error('plain')), {});
  assert.deepEqual(fieldErrors(null), {});
});

test('a network failure becomes a network ApiError with a plain message', async () => {
  const { fetchImpl } = fakeFetch(new TypeError('Failed to fetch'));
  const api = createApi({ baseUrl: '/api', csrfToken: 't', fetchImpl });
  await assert.rejects(api.post('/cart/price', {}), (error) => {
    assert.ok(error instanceof ApiError);
    assert.equal(error.isNetwork, true);
    assert.equal(error.status, 0);
    assert.match(error.message, /could not reach the shop/i);
    return true;
  });
});

test('an answer that is not the envelope (an HTML error page) is an unexpected error, not a crash', async () => {
  const { fetchImpl } = fakeFetch(reply(undefined, 500));
  const api = createApi({ baseUrl: '/api', csrfToken: 't', fetchImpl });
  await assert.rejects(api.get('/x'), (error) => {
    assert.ok(error instanceof ApiError);
    assert.equal(error.status, 500);
    assert.equal(error.code, 'unexpected');
    assert.match(error.message, /went wrong/i);
    return true;
  });
});

test('a failure envelope with a 200 status is still an error, and ok:true with a failing status too', async () => {
  await assert.rejects(readEnvelope(reply({ ok: false, error: { code: 'nope', message: 'No.', fields: {} } }, 200)), (error) => error.code === 'nope');
  await assert.rejects(readEnvelope(reply({ ok: true, data: {} }, 500)), (error) => error.status === 500);
});

test('Retry-After is read from a locked-out answer', async () => {
  await assert.rejects(
    readEnvelope(reply({ ok: false, error: { code: 'too_many_attempts', message: 'Wait.', fields: {} } }, 429, { 'Retry-After': '900' })),
    (error) => error.status === 429 && error.retryAfter === 900,
  );
});

test('a stale CSRF token is refreshed once and the change is retried', async () => {
  const { fetchImpl, calls } = fakeFetch(
    reply({ ok: false, error: { code: 'csrf_mismatch', message: 'Your session could not be verified.', fields: {} } }, 403),
    reply({ ok: true, data: { csrf_token: 'fresh' } }),
    reply({ ok: true, data: { id: 'order-1' } }, 201),
  );
  const api = createApi({ baseUrl: '/api', csrfToken: 'stale', fetchImpl });
  assert.deepEqual(await api.post('/checkout', { a: 1 }), { id: 'order-1' });
  assert.equal(calls.length, 3);
  assert.equal(calls[0].init.headers['X-CSRF-Token'], 'stale');
  assert.equal(calls[1].url, '/api/auth/customer/csrf');
  assert.equal(calls[2].init.headers['X-CSRF-Token'], 'fresh');
});

test('a second CSRF refusal is not retried again', async () => {
  const refusal = () => reply({ ok: false, error: { code: 'csrf_mismatch', message: 'x', fields: {} } }, 403);
  const { fetchImpl, calls } = fakeFetch(refusal(), reply({ ok: true, data: { csrf_token: 'fresh' } }), refusal());
  const api = createApi({ baseUrl: '/api', csrfToken: 'stale', fetchImpl });
  await assert.rejects(api.post('/checkout', {}), (error) => error.code === 'csrf_mismatch');
  assert.equal(calls.length, 3);
});

test('other 403 answers are not retried', async () => {
  const { fetchImpl, calls } = fakeFetch(reply({ ok: false, error: { code: 'forbidden', message: 'No.', fields: {} } }, 403));
  const api = createApi({ baseUrl: '/api', csrfToken: 't', fetchImpl });
  await assert.rejects(api.post('/x', {}), (error) => error.code === 'forbidden');
  assert.equal(calls.length, 1);
});
