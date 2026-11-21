import test from 'node:test';
import assert from 'node:assert/strict';
import { createApi, ApiError } from '../../templates/pos/assets/js/api.js';

const BASE = '/demo/api/v1';

/** A fake fetch: each call is answered by the next function in the script, and every call is recorded. */
function fakeServer(script) {
  const calls = [];
  const fetchImpl = async (url, init) => {
    const step = script.shift();
    assert.ok(step, `unexpected request: ${init.method} ${url}`);
    calls.push({ url, method: init.method, headers: init.headers, body: init.body === undefined ? undefined : JSON.parse(init.body) });
    const answer = await step(url, init);
    if (answer === 'network') throw new TypeError('Failed to fetch');
    return { status: answer.status ?? 200, json: async () => answer.body };
  };
  return { fetchImpl, calls, left: () => script.length };
}

const ok = (data, status = 200) => () => ({ status, body: { ok: true, data } });
const fail = (status, code, message, fields = {}) => () => ({ status, body: { ok: false, error: { code, message, fields } } });

test('a read sends no CSRF header; a change sends it, with the JSON body', async () => {
  const server = fakeServer([ok({ items: [] }), ok({ sale_id: 's1' }, 201)]);
  const api = createApi({ base: BASE, csrf: 'tok-1', fetchImpl: server.fetchImpl });

  await api.terminals();
  await api.post('/pos/sales', { id: 's1' });

  assert.equal(server.calls[0].url, `${BASE}/pos/terminals`);
  assert.equal(server.calls[0].headers['X-CSRF-Token'], undefined);
  assert.equal(server.calls[1].headers['X-CSRF-Token'], 'tok-1');
  assert.equal(server.calls[1].headers['Content-Type'], 'application/json');
  assert.deepEqual(server.calls[1].body, { id: 's1' });
});

test('the envelope is unwrapped and an error becomes an ApiError with its code and fields', async () => {
  const server = fakeServer([ok({ hello: 'world' }), fail(422, 'validation_failed', 'Please correct the highlighted fields.', { 'payments.0.reference': 'Enter the reference number for this payment.' })]);
  const api = createApi({ base: BASE, csrf: 't', fetchImpl: server.fetchImpl });

  assert.deepEqual(await api.get('/x'), { hello: 'world' });
  await assert.rejects(() => api.post('/pos/sales', {}), (error) => {
    assert.ok(error instanceof ApiError);
    assert.equal(error.status, 422);
    assert.equal(error.code, 'validation_failed');
    assert.equal(error.network, false);
    assert.equal(error.fields['payments.0.reference'], 'Enter the reference number for this payment.');
    return true;
  });
});

test('an answer that is not the JSON envelope is a readable error, not a crash', async () => {
  const api = createApi({ base: BASE, csrf: 't', fetchImpl: async () => ({ status: 502, json: async () => { throw new SyntaxError('not json'); } }) });
  await assert.rejects(() => api.get('/x'), (error) => error instanceof ApiError && error.status === 502 && error.message.length > 0);
});

test('a network failure is marked as one, and the online indicator is told', async () => {
  const states = [];
  const server = fakeServer(['network', ok({ items: [] })]);
  const api = createApi({ base: BASE, csrf: 't', fetchImpl: server.fetchImpl, onNetwork: (online) => states.push(online) });

  await assert.rejects(() => api.post('/pos/sales', {}), (error) => error.network === true && error.status === 0 && /Cannot reach the server/.test(error.message));
  await api.terminals();
  assert.deepEqual(states, [false, true]);
});

test('a 401 opens sign-in, signs in with a fresh token, and sends the same call again', async () => {
  const sale = { id: 'sale-1', lines: [{ variant_id: 'v', quantity: '1.000', unit_price: '112.00' }] };
  const server = fakeServer([
    fail(401, 'unauthorized', 'Please sign in.'),                       // the sale, session ended
    ok({ csrf_token: 'anon-token' }),                                    // GET csrf for the new anonymous session
    ok({ user: { id: 'u2', full_name: 'Casey' }, csrf_token: 'new-token' }), // POST login
    ok({ sale_id: 'sale-1', duplicate: false }, 201),                    // the sale again
  ]);
  const sessions = [];
  let dialogs = 0;
  const api = createApi({
    base: BASE, csrf: 'old-token', fetchImpl: server.fetchImpl,
    onSession: (session) => sessions.push(session),
    requestSignIn: async (a) => { dialogs += 1; await a.login('casey@example.test', 'secret'); },
  });

  const result = await api.postSale(sale);

  assert.equal(result.sale_id, 'sale-1');
  assert.equal(dialogs, 1);
  assert.deepEqual(server.calls.map((c) => `${c.method} ${c.url.replace(BASE, '')}`), ['POST /pos/sales', 'GET /auth/staff/csrf', 'POST /auth/staff/login', 'POST /pos/sales']);
  assert.equal(server.calls[2].headers['X-CSRF-Token'], 'anon-token', 'the sign-in carries the fresh token');
  assert.deepEqual(server.calls[2].body, { email: 'casey@example.test', password: 'secret' });
  assert.equal(server.calls[3].headers['X-CSRF-Token'], 'new-token', 'the retry carries the token from the sign-in');
  assert.deepEqual(server.calls[3].body, sale, 'the very same ticket is sent again');
  assert.deepEqual(sessions, [{ user: { id: 'u2', full_name: 'Casey' }, csrf: 'new-token' }]);
  assert.equal(api.csrfToken(), 'new-token');
});

test('a session that ended during a sale shows as csrf_mismatch first: refresh the token, then sign in, then retry', async () => {
  const sale = { id: 'sale-2' };
  const server = fakeServer([
    fail(403, 'csrf_mismatch', 'Your session expired.'),
    ok({ csrf_token: 'fresh-anon' }),
    fail(401, 'unauthorized', 'Please sign in.'),
    ok({ csrf_token: 'fresh-anon-2' }),
    ok({ user: { id: 'u1', full_name: 'Ana' }, csrf_token: 'signed-in-token' }),
    ok({ sale_id: 'sale-2', duplicate: false }, 201),
  ]);
  const api = createApi({
    base: BASE, csrf: 'stale', fetchImpl: server.fetchImpl,
    requestSignIn: async (a) => { await a.login('ana@example.test', 'pw'); },
  });

  const result = await api.postSale(sale);

  assert.equal(result.sale_id, 'sale-2');
  assert.equal(server.left(), 0);
  assert.equal(server.calls.at(-1).headers['X-CSRF-Token'], 'signed-in-token');
  assert.deepEqual(server.calls.at(-1).body, sale);
});

test('a stale CSRF token alone is fixed quietly, with no sign-in', async () => {
  const server = fakeServer([fail(403, 'csrf_mismatch', 'Bad token.'), ok({ csrf_token: 'fresh' }), ok({ ok: 1 })]);
  let dialogs = 0;
  const api = createApi({ base: BASE, csrf: 'stale', fetchImpl: server.fetchImpl, requestSignIn: async () => { dialogs += 1; } });

  assert.deepEqual(await api.post('/pos/shifts', {}), { ok: 1 });
  assert.equal(dialogs, 0);
  assert.equal(server.calls[2].headers['X-CSRF-Token'], 'fresh');
});

test('a read that gets a 401 also signs in and retries', async () => {
  const server = fakeServer([fail(401, 'unauthorized', 'Please sign in.'), ok({ csrf_token: 'a' }), ok({ user: {}, csrf_token: 'b' }), ok({ items: [{ id: 't1' }] })]);
  const api = createApi({ base: BASE, csrf: 'x', fetchImpl: server.fetchImpl, requestSignIn: async (a) => { await a.login('e', 'p'); } });

  assert.deepEqual(await api.terminals(), [{ id: 't1' }]);
});

test('if the person gives up signing in, the original 401 comes back and nothing is retried', async () => {
  const server = fakeServer([fail(401, 'unauthorized', 'Please sign in.')]);
  const api = createApi({ base: BASE, csrf: 'x', fetchImpl: server.fetchImpl, requestSignIn: async () => { throw new Error('cancelled'); } });

  await assert.rejects(() => api.postSale({ id: 's' }), (error) => error.status === 401 && error.code === 'unauthorized');
  assert.equal(server.calls.length, 1);
});

test('without a sign-in dialog a 401 is simply thrown', async () => {
  const server = fakeServer([fail(401, 'unauthorized', 'Please sign in.')]);
  const api = createApi({ base: BASE, csrf: 'x', fetchImpl: server.fetchImpl });

  await assert.rejects(() => api.get('/x'), (error) => error.status === 401);
});

test('a 401 that keeps coming after signing in is not retried forever', async () => {
  const server = fakeServer([
    fail(401, 'unauthorized', 'No.'), ok({ csrf_token: 'a' }), ok({ user: {}, csrf_token: 'b' }), fail(401, 'unauthorized', 'Still no.'),
  ]);
  const api = createApi({ base: BASE, csrf: 'x', fetchImpl: server.fetchImpl, requestSignIn: async (a) => { await a.login('e', 'p'); } });

  await assert.rejects(() => api.get('/x'), (error) => error.status === 401 && error.message === 'Still no.');
  assert.equal(server.left(), 0);
});

test('two calls that are refused at once share one sign-in dialog', async () => {
  const server = fakeServer([
    fail(401, 'unauthorized', 'a'), fail(401, 'unauthorized', 'b'),
    ok({ csrf_token: 't' }), ok({ user: {}, csrf_token: 'n' }),
    ok({ n: 1 }), ok({ n: 2 }),
  ]);
  let dialogs = 0;
  const api = createApi({ base: BASE, csrf: 'x', fetchImpl: server.fetchImpl, requestSignIn: async (a) => { dialogs += 1; await a.login('e', 'p'); } });

  const [one, two] = await Promise.all([api.get('/one'), api.get('/two')]);
  assert.deepEqual([one.n, two.n], [1, 2]);
  assert.equal(dialogs, 1);
});

test('a wrong password in the dialog is an error for the dialog, and does not open another one', async () => {
  const server = fakeServer([fail(401, 'unauthorized', 'Please sign in.'), ok({ csrf_token: 'a' }), fail(401, 'invalid_credentials', 'Those details are not right.')]);
  let dialogs = 0;
  let loginError = null;
  const api = createApi({
    base: BASE, csrf: 'x', fetchImpl: server.fetchImpl,
    requestSignIn: async (a) => {
      dialogs += 1;
      try { await a.login('e', 'wrong'); } catch (error) { loginError = error; throw error; }
    },
  });

  await assert.rejects(() => api.get('/x'), (error) => error.status === 401 && error.code === 'unauthorized');
  assert.equal(dialogs, 1);
  assert.equal(loginError.code, 'invalid_credentials');
});

test('the POS endpoints are written once, with the ids encoded', async () => {
  const server = fakeServer([ok({}), ok({}), ok({}), ok({}), ok({}), ok({}), ok({})]);
  const api = createApi({ base: BASE, csrf: 't', fetchImpl: server.fetchImpl });

  await api.snapshot('a/b');
  await api.currentShift('t1');
  await api.openShift('t1', '2000.00');
  await api.closeShift('s1', '5230.50');
  await api.postSale({ id: 'x' });
  await api.getSale('x');
  await api.logout();

  assert.deepEqual(server.calls.map((c) => `${c.method} ${c.url.replace(BASE, '')}`), [
    'GET /pos/terminals/a%2Fb/snapshot', 'GET /pos/terminals/t1/shift', 'POST /pos/shifts', 'POST /pos/shifts/s1/close',
    'POST /pos/sales', 'GET /pos/sales/x', 'POST /auth/staff/logout',
  ]);
  assert.deepEqual(server.calls[2].body, { terminal_id: 't1', opening_cash: '2000.00' });
  assert.deepEqual(server.calls[3].body, { counted_cash: '5230.50' });
});
