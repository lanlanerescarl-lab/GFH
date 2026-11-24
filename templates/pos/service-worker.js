'use strict';

// The POS service worker. core/View/PosServiceWorker.php fills in the CONFIG constant below, replacing its placeholder, with this organization's cache
// names and the list of files to keep. The worker holds no secret: no CSRF token, no user name, no session value.
//
// What it does, and what it never does:
//   - the shell page (/{org}/pos) is fetched from the network first; the saved copy is only the offline fallback;
//   - published scripts, styles and fonts are served from the cache first (the cache name changes with every
//     deployment, so a new version starts clean and the old caches are deleted when it takes over);
//   - product thumbnails are saved as they are used, at most MAX entries, oldest dropped;
//   - anything under /api/ goes straight to the network and is never cached;
//   - the sign-in page is never served from the cache; redirects and error answers are never cached;
//   - the saved copy of the shell has its CSRF token and the signed-in person's name taken out (sanitizeShell), so
//     neither is ever written to the Cache API. The app reads who is signed in from the shift it stored.
//   - a new version waits (no skipWaiting, no claim) and takes over on the next full load, never in the middle of a sale.

const CONFIG = __CONFIG__;

// Headers kept on the saved shell. Everything else (cookies above all) is dropped, but the security headers stay.
const KEEP_HEADERS = ['content-type', 'content-security-policy', 'x-content-type-options', 'x-frame-options', 'referrer-policy'];

/** A response worth keeping: a plain 200 from this origin, not the end of a redirect. */
function cacheable(response) {
  return response.status === 200 && response.type === 'basic' && !response.redirected;
}

function escapeForScript(json) {
  return json.replace(/</g, '\\u003C').replace(/>/g, '\\u003E').replace(/&/g, '\\u0026').replace(/'/g, '\\u0027');
}

/** The shell's HTML without the CSRF token and the person's identity. Returns null when the page is not the shape expected. */
function sanitizeShell(html) {
  const block = /(<script type="application\/json" id="pos-config">)([\s\S]*?)(<\/script>)/;
  const found = block.exec(html);
  if (found === null) return null;

  let config;
  try {
    config = JSON.parse(found[2]);
  } catch {
    return null;
  }
  config.csrf = '';
  config.user = { id: '', name: '' };

  return html
    .replace(block, (all, open, body, close) => open + escapeForScript(JSON.stringify(config)) + close)
    .replace(/<input\b[^>]*\bname="_csrf"[^>]*>/g, (tag) => tag.replace(/\bvalue="[^"]*"/, 'value=""'));
}

async function putShell(cache, response) {
  if (!cacheable(response) || !(response.headers.get('content-type') || '').startsWith('text/html')) return;
  const sanitized = sanitizeShell(await response.clone().text());
  if (sanitized === null) return;

  const headers = new Headers();
  for (const name of KEEP_HEADERS) {
    const value = response.headers.get(name);
    if (value !== null) headers.set(name, value);
  }
  await cache.put(CONFIG.shellUrl, new Response(sanitized, { status: 200, headers }));
}

async function precache() {
  const cache = await caches.open(CONFIG.cacheName);
  await Promise.all(CONFIG.precache.map(async (url) => {
    try {
      const response = await fetch(new Request(url, { cache: 'reload', credentials: 'same-origin' }));
      if (url === CONFIG.shellUrl) await putShell(cache, response);
      else if (cacheable(response)) await cache.put(url, response);
    } catch {
      // One file that cannot be fetched now is fetched (and kept) the first time it is used.
    }
  }));
}

function offlineNotice() {
  return new Response(
    '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Point of sale</title>'
    + '<body style="font-family:sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem">'
    + '<h1>The point of sale cannot open yet</h1>'
    + '<p>There is no connection, and this device has no saved copy of the POS. Connect to the internet and reload this page once. After that it opens without a connection.</p>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
  );
}

async function shell(event) {
  const cache = await caches.open(CONFIG.cacheName);
  try {
    const response = await fetch(event.request);
    // putShell checks the live response and clones it before the page starts reading it.
    event.waitUntil(putShell(cache, response).catch(() => {}));
    if ([502, 503, 504].includes(response.status)) {
      const saved = await cache.match(CONFIG.shellUrl);
      if (saved !== undefined) return saved;
    }
    return response;
  } catch {
    return (await cache.match(CONFIG.shellUrl)) ?? offlineNotice();
  }
}

async function cacheFirst(request) {
  const cache = await caches.open(CONFIG.cacheName);
  const hit = await cache.match(request.url);
  if (hit !== undefined) return hit;

  const response = await fetch(request);
  if (cacheable(response)) await cache.put(request.url, response.clone());
  return response;
}

async function staleWhileRevalidate(request, event) {
  const cache = await caches.open(CONFIG.cacheName);
  const hit = await cache.match(request.url);
  const refresh = fetch(request).then(async (response) => {
    if (cacheable(response)) await cache.put(request.url, response.clone());
    return response;
  });
  if (hit === undefined) return refresh;
  event.waitUntil(refresh.catch(() => {}));
  return hit;
}

async function thumbnail(request) {
  const cache = await caches.open(CONFIG.mediaCacheName);
  const hit = await cache.match(request.url);
  if (hit !== undefined) return hit;

  const response = await fetch(request);
  if (cacheable(response) && (response.headers.get('content-type') || '').startsWith('image/')) {
    await cache.put(request.url, response.clone());
    const keys = await cache.keys(); // oldest first
    for (const old of keys.slice(0, Math.max(0, keys.length - CONFIG.maxThumbnails))) await cache.delete(old);
  }
  return response;
}

self.addEventListener('install', (event) => {
  event.waitUntil(precache());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    for (const name of await caches.keys()) {
      if (name.startsWith(CONFIG.cachePrefix) && name !== CONFIG.cacheName && name !== CONFIG.mediaCacheName) await caches.delete(name);
    }
  })());
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  const path = url.pathname;

  // Left alone, these go to the network exactly as if there were no worker.
  if (path.startsWith(CONFIG.apiPrefix)) return;
  if (path === CONFIG.loginPath || path.startsWith(`${CONFIG.loginPath}/`)) return;
  if (path === CONFIG.workerPath) return;

  if (path === CONFIG.shellUrl && request.mode === 'navigate') {
    event.respondWith(shell(event));
  } else if (path.startsWith(CONFIG.mediaPrefix) && url.searchParams.get('thumb') === '1') {
    event.respondWith(thumbnail(request));
  } else if (path === CONFIG.brandingUrl) {
    event.respondWith(staleWhileRevalidate(request, event));
  } else if (path.startsWith(CONFIG.assetsPrefix)) {
    event.respondWith(cacheFirst(request));
  }
});

self.addEventListener('message', (event) => {
  if (event.data === null || typeof event.data !== 'object' || event.data.type !== 'forget-shell') return;

  event.waitUntil((async () => {
    // Every cache of this organization, so a copy left by an older version goes too.
    for (const name of await caches.keys()) {
      if (name.startsWith(CONFIG.cachePrefix)) await (await caches.open(name)).delete(CONFIG.shellUrl);
    }
    if (event.ports && event.ports[0]) event.ports[0].postMessage({ ok: true });
  })());
});
