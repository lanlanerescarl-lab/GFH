import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
import { settle } from './support.js';

// The service worker is the PHP template with its placeholder filled in. Here it runs in a vm with a fake Cache API.
// No real browser and no real service worker is involved: these tests prove the worker's logic only.

const TEMPLATE = readFileSync(new URL('../../templates/pos/service-worker.js', import.meta.url), 'utf8');
const ORIGIN = 'https://pos.test';
const CONFIG = {
  cacheName: 'gfh-pos-demo-v-new', cachePrefix: 'gfh-pos-demo-', mediaCacheName: 'gfh-pos-demo-media', maxThumbnails: 3,
  shellUrl: '/demo/pos', loginPath: '/demo/pos/login', workerPath: '/demo/pos/sw.js', apiPrefix: '/demo/api/',
  mediaPrefix: '/media/demo/', brandingUrl: '/demo/branding.css', assetsPrefix: '/assets/',
  precache: ['/demo/pos', '/assets/pos/js/app.js', '/assets/css/base.css'],
};

const SHELL = (csrf = 'tok-SECRET-9', name = 'Casey } "Q" Cashier') => `<!doctype html><html><body>
<form method="post" action="/demo/pos/logout"><input type="hidden" name="_csrf" value="${csrf}" data-csrf-field></form>
<script type="application/json" id="pos-config">${JSON.stringify({ apiBase: '/demo/api/v1', csrf, posBase: '/demo/pos', user: { id: 'user-1', name } }).replace(/</g, '\\u003C').replace(/>/g, '\\u003E')}</script></body></html>`;

/** A Response that reports the type and redirect state a browser would. */
function reply(body, { status = 200, type = 'basic', redirected = false, headers = { 'Content-Type': 'text/html; charset=utf-8' } } = {}) {
  const response = new Response(body, { status, headers });
  Object.defineProperty(response, 'type', { value: type });
  Object.defineProperty(response, 'redirected', { value: redirected });
  return response;
}

function boot({ config = CONFIG, network = {} } = {}) {
  const listeners = {};
  const stores = new Map();
  const fetched = [];
  const cacheApi = {
    stores,
    async open(name) {
      if (!stores.has(name)) stores.set(name, new Map());
      const rows = stores.get(name);
      const key = (request) => (typeof request === 'string' ? request : request.url);
      return {
        async match(request) { const hit = rows.get(key(request)); return hit === undefined ? undefined : hit.clone(); },
        async put(request, response) { rows.set(key(request), response.clone()); },
        async delete(request) { return rows.delete(key(request)); },
        async keys() { return [...rows.keys()]; },
      };
    },
    async keys() { return [...stores.keys()]; },
    async delete(name) { return stores.delete(name); },
  };
  const context = {
    self: { location: { origin: ORIGIN }, addEventListener: (name, fn) => { listeners[name] = fn; } },
    caches: cacheApi, Response, Headers, URL,
    // A browser resolves a relative address against the worker's own location; Node's Request needs it spelled out.
    Request: class extends Request { constructor(input, init) { super(typeof input === 'string' ? new URL(input, ORIGIN).href : input, init); } },
    fetch: async (request) => {
      const url = new URL(typeof request === 'string' ? request : request.url);
      fetched.push(url.pathname + url.search);
      const next = network[url.pathname];
      if (next instanceof Error) throw next;
      if (typeof next === 'function') return next(request);
      throw new TypeError('Failed to fetch');
    },
  };
  vm.createContext(context);
  vm.runInContext(TEMPLATE.replace('__CONFIG__', JSON.stringify(config)), context);

  const waits = [];
  return {
    cacheApi, fetched, listeners, network,
    async install() { const w = []; listeners.install({ waitUntil: (p) => w.push(p) }); await Promise.all(w); },
    async activate() { const w = []; listeners.activate({ waitUntil: (p) => w.push(p) }); await Promise.all(w); },
    /** Dispatches a fetch event. Returns the response, or undefined when the worker left the request to the network. */
    async fetchEvent(url, { method = 'GET', mode = 'no-cors' } = {}) {
      const request = Object.assign(new Request(new URL(url, ORIGIN), { method }), {});
      Object.defineProperty(request, 'mode', { value: mode });
      let handled;
      listeners.fetch({ request, respondWith: (p) => { handled = Promise.resolve(p); }, waitUntil: (p) => waits.push(p) });
      const response = handled === undefined ? undefined : await handled;
      await Promise.all(waits.splice(0));
      return response;
    },
    async message(data) {
      const replies = [];
      const w = [];
      listeners.message({ data, ports: [{ postMessage: (m) => replies.push(m) }], waitUntil: (p) => w.push(p) });
      await Promise.all(w);
      return JSON.parse(JSON.stringify(replies)); // objects made inside the vm belong to another realm
    },
    texts: async (name, url) => (await (await cacheApi.open(name)).match(url))?.text(),
  };
}

const shellNetwork = (csrf, name) => ({ '/demo/pos': () => reply(SHELL(csrf, name), { headers: { 'Content-Type': 'text/html; charset=utf-8', 'Content-Security-Policy': "default-src 'self'", 'Set-Cookie': 'gfh_session=SESSIONVALUE' } }) });

test('the worker takes over only on the next full load: no skipWaiting, no claim', () => {
  const code = TEMPLATE.split('\n').filter((line) => !line.trim().startsWith('//')).join('\n');
  assert.equal(/skipWaiting|clients\s*\.\s*claim/.test(code), false);
});

test('install keeps the listed files and the shell; the saved shell has no CSRF token and no name', async () => {
  const w = boot({ network: { ...shellNetwork(), '/assets/pos/js/app.js': () => reply('export {}', { headers: { 'Content-Type': 'text/javascript' } }), '/assets/css/base.css': () => reply('a{}', { headers: { 'Content-Type': 'text/css' } }) } });
  await w.install();

  assert.deepEqual([...w.cacheApi.stores.get('gfh-pos-demo-v-new').keys()].sort(), ['/assets/css/base.css', '/assets/pos/js/app.js', '/demo/pos']);
  const saved = await w.texts('gfh-pos-demo-v-new', '/demo/pos');
  assert.equal(saved.includes('tok-SECRET-9'), false, 'no CSRF token in the Cache API');
  assert.equal(saved.includes('Casey'), false, 'no name in the Cache API');
  assert.equal(saved.includes('SESSIONVALUE'), false);
  const config = JSON.parse(/id="pos-config">([\s\S]*?)<\/script>/.exec(saved)[1]);
  assert.deepEqual([config.csrf, config.user, config.posBase], ['', { id: '', name: '' }, '/demo/pos'], 'the rest of the config survives');
  assert.match(saved, /name="_csrf" value="" data-csrf-field/);
  const kept = (await (await w.cacheApi.open('gfh-pos-demo-v-new')).match('/demo/pos')).headers;
  assert.equal(kept.get('content-security-policy'), "default-src 'self'", 'the security headers stay');
  assert.equal(kept.get('set-cookie'), null, 'cookies never');
});

test('a redirect, an error answer and an opaque answer are never cached', async () => {
  const w = boot({ network: {
    '/demo/pos': () => reply(SHELL(), { redirected: true }),                       // ended at the sign-in page
    '/assets/pos/js/app.js': () => reply('oops', { status: 500 }),
    '/assets/css/base.css': () => reply('', { status: 0, type: 'opaqueredirect' }),
  } });
  await w.install();
  assert.equal([...w.cacheApi.stores.get('gfh-pos-demo-v-new').keys()].length, 0);

  const live = boot({ network: { '/assets/x.js': () => reply('bad', { status: 404 }) } });
  const response = await live.fetchEvent('/assets/x.js');
  assert.equal(response.status, 404, 'the error is passed on');
  assert.equal(live.cacheApi.stores.get('gfh-pos-demo-v-new')?.size ?? 0, 0, 'but not kept');
});

test('the shell page is network first; the saved copy is the offline fallback', async () => {
  const w = boot({ network: shellNetwork('tok-A', 'Ann') });
  const online = await w.fetchEvent('/demo/pos', { mode: 'navigate' });
  assert.match(await online.text(), /tok-A/, 'online: the live page, with its real token');
  assert.equal((await w.texts('gfh-pos-demo-v-new', '/demo/pos')).includes('tok-A'), false, 'but the saved copy has none');

  w.network['/demo/pos'] = new TypeError('offline');
  const offline = await w.fetchEvent('/demo/pos', { mode: 'navigate' });
  assert.equal(offline.status, 200);
  assert.match(await offline.text(), /pos-config/);
});

test('offline with nothing saved yet the worker says so, in plain words', async () => {
  const w = boot({ network: { '/demo/pos': new TypeError('offline') } });
  const response = await w.fetchEvent('/demo/pos', { mode: 'navigate' });
  assert.equal(response.status, 503);
  assert.match(await response.text(), /cannot open yet.*Connect to the internet/s);
});

test('a gateway error from the server falls back to the saved copy, any other error page does not', async () => {
  const w = boot({ network: shellNetwork() });
  await w.fetchEvent('/demo/pos', { mode: 'navigate' });
  w.network['/demo/pos'] = () => reply('bad gateway', { status: 502 });
  assert.match(await (await w.fetchEvent('/demo/pos', { mode: 'navigate' })).text(), /pos-config/);
  w.network['/demo/pos'] = () => reply('forbidden', { status: 403 });
  assert.equal((await w.fetchEvent('/demo/pos', { mode: 'navigate' })).status, 403);
});

test('the sign-in page, the API, the worker itself, other methods and other origins are left to the network', async () => {
  const w = boot({});
  for (const path of ['/demo/pos/login', '/demo/pos/login/', '/demo/api/v1/pos/snapshot', '/demo/api/', '/demo/pos/sw.js']) {
    assert.equal(await w.fetchEvent(path, { mode: 'navigate' }), undefined, path);
  }
  assert.equal(await w.fetchEvent('/demo/pos', { method: 'POST', mode: 'navigate' }), undefined);
  assert.equal(await w.fetchEvent('https://elsewhere.test/assets/x.js'), undefined);
  assert.equal(w.fetched.length, 0, 'the worker itself fetched nothing');
});

test('nothing under /api/ is ever written to a cache, even when it is requested a lot', async () => {
  const w = boot({ network: { '/demo/api/v1/pos/snapshot': () => reply('{}', { headers: { 'Content-Type': 'application/json' } }) } });
  for (let i = 0; i < 3; i += 1) await w.fetchEvent('/demo/api/v1/pos/snapshot');
  assert.equal([...w.cacheApi.stores.values()].reduce((sum, rows) => sum + rows.size, 0), 0);
});

test('scripts, styles and fonts are cache first: the second request never reaches the network', async () => {
  const w = boot({ network: { '/assets/pos/js/app.js': () => reply('export {}', { headers: { 'Content-Type': 'text/javascript' } }) } });
  await w.fetchEvent('/assets/pos/js/app.js');
  const again = await w.fetchEvent('/assets/pos/js/app.js');
  assert.equal(await again.text(), 'export {}');
  assert.equal(w.fetched.filter((path) => path === '/assets/pos/js/app.js').length, 1);
});

test('the branding stylesheet is served from the cache and refreshed behind it', async () => {
  let version = 'a{color:red}';
  const w = boot({ network: { '/demo/branding.css': () => reply(version, { headers: { 'Content-Type': 'text/css' } }) } });
  assert.equal(await (await w.fetchEvent('/demo/branding.css')).text(), 'a{color:red}');
  version = 'a{color:blue}';
  assert.equal(await (await w.fetchEvent('/demo/branding.css')).text(), 'a{color:red}', 'cache first');
  assert.equal(await (await w.fetchEvent('/demo/branding.css')).text(), 'a{color:blue}', 'the refresh landed');
});

test('thumbnails are cached as they are used, only with ?thumb=1, and at most the limit, oldest dropped', async () => {
  const image = () => reply('png', { headers: { 'Content-Type': 'image/png' } });
  const w = boot({ network: Object.fromEntries([1, 2, 3, 4, 5].map((n) => [`/media/demo/m${n}`, image])) });

  assert.equal(await w.fetchEvent('/media/demo/m1'), undefined, 'a full-size picture is not cached by this rule');
  for (const n of [1, 2, 3, 4, 5]) await w.fetchEvent(`/media/demo/m${n}?thumb=1`);
  const kept = [...w.cacheApi.stores.get('gfh-pos-demo-media').keys()].map((url) => new URL(url).pathname);
  assert.deepEqual(kept, ['/media/demo/m3', '/media/demo/m4', '/media/demo/m5']);

  const before = w.fetched.length;
  await w.fetchEvent('/media/demo/m5?thumb=1');
  assert.equal(w.fetched.length, before, 'served from the cache');
});

test('a thumbnail that is not an image, or not a 200, is not kept', async () => {
  const w = boot({ network: { '/media/demo/a': () => reply('<html>', { headers: { 'Content-Type': 'text/html' } }), '/media/demo/b': () => reply('', { status: 404, headers: { 'Content-Type': 'image/png' } }) } });
  await w.fetchEvent('/media/demo/a?thumb=1');
  await w.fetchEvent('/media/demo/b?thumb=1');
  assert.equal(w.cacheApi.stores.get('gfh-pos-demo-media')?.size ?? 0, 0);
});

test('signing out: the saved shell is deleted from every cache of the organization and the page is answered', async () => {
  const w = boot({ network: { ...shellNetwork(), '/assets/pos/js/app.js': () => reply('export {}', { headers: { 'Content-Type': 'text/javascript' } }) } });
  await w.install();
  await w.cacheApi.stores.set('gfh-pos-demo-v-old', new Map([['/demo/pos', reply('old')], ['/assets/x.js', reply('x')]]));
  await w.cacheApi.stores.set('gfh-pos-other-v-1', new Map([['/demo/pos', reply('another org')]]));

  const replies = await w.message({ type: 'forget-shell' });
  assert.deepEqual(replies, [{ ok: true }]);
  assert.equal(w.cacheApi.stores.get('gfh-pos-demo-v-new').has('/demo/pos'), false);
  assert.equal(w.cacheApi.stores.get('gfh-pos-demo-v-old').has('/demo/pos'), false);
  assert.equal(w.cacheApi.stores.get('gfh-pos-demo-v-new').has('/assets/pos/js/app.js'), true, 'scripts stay');
  assert.equal(w.cacheApi.stores.get('gfh-pos-other-v-1').has('/demo/pos'), true, 'another organization is not touched');
  assert.deepEqual(await w.message({ type: 'something-else' }), []);
  assert.deepEqual(await w.message(null), []);
});

test('activating deletes the older versions of this organization and nothing else', async () => {
  const w = boot({});
  for (const name of ['gfh-pos-demo-v-old', 'gfh-pos-demo-v-new', 'gfh-pos-demo-media', 'gfh-pos-other-v-1', 'unrelated']) await w.cacheApi.open(name);
  await w.activate();
  assert.deepEqual([...w.cacheApi.stores.keys()].sort(), ['gfh-pos-demo-media', 'gfh-pos-demo-v-new', 'gfh-pos-other-v-1', 'unrelated']);
});

test('a page whose config block is missing or broken is not saved at all', async () => {
  const w = boot({ network: { '/demo/pos': () => reply('<html>no config here</html>') } });
  await w.install();
  assert.equal(w.cacheApi.stores.get('gfh-pos-demo-v-new').has('/demo/pos'), false);
  void settle;
});
