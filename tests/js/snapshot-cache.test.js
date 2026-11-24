import test from 'node:test';
import assert from 'node:assert/strict';
import { createSnapshotStore } from '../../templates/pos/assets/js/snapshot.js';
import { createMemoryStore } from '../../templates/pos/assets/js/store.js';
import { snapshotBanner, formatAge, snapshotAgeMs, STALE_AFTER_MS } from '../../templates/pos/assets/js/freshness.js';
import { thumbnailUrls, warmThumbnails, MAX_THUMBNAILS } from '../../templates/pos/assets/js/thumbs.js';
import { networkError, apiError, createClock } from './support.js';

const snap = (etag = 'e1', extra = {}) => ({ etag, terminal: { id: 'term-1', code: 'T3' }, products: [{ variant_id: 'v1', name: 'Rice', media_id: 'm1' }, { variant_id: 'v2', name: 'Oil', media_id: null }], ...extra });
const online = (data) => ({ snapshot: async () => data });
const dead = (error = networkError()) => ({ snapshot: async () => { throw error; } });

test('online: the snapshot is downloaded, held in memory, and stored with its etag and the time', async () => {
  const store = createMemoryStore();
  const clock = createClock();
  const cache = createSnapshotStore(online(snap()), { store, now: clock.now });
  const data = await cache.load('term-1');

  assert.equal(data.etag, 'e1');
  assert.equal(cache.get().terminal.code, 'T3');
  assert.equal(cache.productsByVariant().get('v1').name, 'Rice');
  assert.deepEqual(cache.info(), { source: 'network', downloadedAt: clock.now(), etag: 'e1', changed: true, error: null });
  const saved = await store.get('snapshot', 'term-1');
  assert.deepEqual([saved.etag, saved.downloaded_at, saved.snapshot.products.length], ['e1', clock.now(), 2]);
});

test('a second download with the same etag reports no change', async () => {
  const store = createMemoryStore();
  const cache = createSnapshotStore(online(snap()), { store });
  await cache.load('term-1');
  await cache.load('term-1');
  assert.equal(cache.info().changed, false);
});

test('offline: the stored snapshot is used, with its own time', async () => {
  const store = createMemoryStore();
  const clock = createClock();
  await createSnapshotStore(online(snap('e9')), { store, now: clock.now }).load('term-1');
  const later = clock.now() + 3 * 60 * 60 * 1000;

  const cache = createSnapshotStore(dead(), { store, now: () => later });
  const data = await cache.load('term-1');
  assert.equal(data.etag, 'e9');
  assert.equal(cache.info().source, 'stored');
  assert.equal(cache.info().downloadedAt, clock.now(), 'the age counts from the original download');
  assert.equal(cache.productsByVariant().get('v2').name, 'Oil');
});

test('when the download fails for any reason the stored copy is used', async () => {
  const store = createMemoryStore();
  await createSnapshotStore(online(snap()), { store }).load('term-1');
  const cache = createSnapshotStore(dead(apiError(500, 'Server error.')), { store });
  await cache.load('term-1');
  assert.equal(cache.info().source, 'stored');
  assert.equal(cache.info().error.status, 500);
});

test('no stored copy and no connection says so plainly', async () => {
  const cache = createSnapshotStore(dead(), { store: createMemoryStore() });
  await assert.rejects(cache.load('term-1'), (error) => error.network === true && /no saved price list on this device/i.test(error.message));
});

test('a store that cannot be written does not stop an online download', async () => {
  const store = createMemoryStore();
  store.failWhen((op) => op.name === 'snapshot');
  const cache = createSnapshotStore(online(snap()), { store });
  await cache.load('term-1');
  assert.equal(cache.get().etag, 'e1');
  assert.equal(cache.info().saveFailed, true);
});

test('a fresh download tells the app so it can warm the thumbnails', async () => {
  let told = null;
  const cache = createSnapshotStore(online(snap()), { onDownloaded: (data) => { told = data; } });
  await cache.load('term-1');
  assert.equal(told.etag, 'e1');
});

// ---------------------------------------------------------------- banner

const H = 60 * 60 * 1000;
test('banner states: none, ok with the age, warning past 24 hours, missing', () => {
  const now = Date.parse('2026-11-23T12:00:00Z');
  assert.equal(snapshotBanner({ downloadedAt: null, nowMs: now, online: true }).level, 'none');
  assert.equal(snapshotBanner({ downloadedAt: null, nowMs: now, online: false }).level, 'missing');
  assert.match(snapshotBanner({ downloadedAt: null, nowMs: now, online: false }).text, /no saved prices and stock.*no connection/i);

  const ok = snapshotBanner({ downloadedAt: now - 5 * 60 * 1000, nowMs: now, online: true, source: 'network' });
  assert.deepEqual([ok.level, ok.text], ['ok', 'Prices and stock were updated 5 minutes ago.']);
  const offline = snapshotBanner({ downloadedAt: now - 3 * H, nowMs: now, online: false, source: 'stored' });
  assert.match(offline.text, /3 hours ago\. Working offline from the saved copy/);

  const edge = snapshotBanner({ downloadedAt: now - STALE_AFTER_MS, nowMs: now, online: true });
  assert.equal(edge.level, 'ok', 'exactly 24 hours is still fine');
  const stale = snapshotBanner({ downloadedAt: now - 26 * H, nowMs: now, online: false, source: 'stored' });
  assert.equal(stale.level, 'warning');
  assert.match(stale.text, /1 day ago.*keep selling/i);
});

test('ages are rounded down and a clock that ran backwards reads as zero', () => {
  assert.deepEqual([0, 59999, 60000, 3600000, 7200000 + 1, 86400000, 172800000].map(formatAge), ['less than a minute', 'less than a minute', '1 minute', '1 hour', '2 hours', '1 day', '2 days']);
  assert.equal(snapshotAgeMs(1000, 500), 0);
});

// ---------------------------------------------------------------- thumbnails

test('thumbnail addresses: one per picture, the media route with ?thumb=1, at most 400', () => {
  const products = [{ media_id: 'a' }, { media_id: 'a' }, { media_id: null }, { media_id: 'b c' }, {}];
  assert.deepEqual(thumbnailUrls(products, '/media/demo'), ['/media/demo/a?thumb=1', '/media/demo/b%20c?thumb=1']);
  const many = Array.from({ length: 600 }, (_, i) => ({ media_id: `m${i}` }));
  assert.equal(thumbnailUrls(many, '/media/demo').length, MAX_THUMBNAILS);
});

test('warming fetches each picture, ignores failures, stops when offline, and returns how many worked', async () => {
  const seen = [];
  const fetchImpl = async (url) => { seen.push(url); if (url.includes('bad')) throw new Error('boom'); return { ok: !url.includes('gone'), blob: async () => null }; };
  const products = [{ media_id: 'a' }, { media_id: 'bad' }, { media_id: 'gone' }, { media_id: 'b' }];
  assert.equal(await warmThumbnails({ products }, { mediaBase: '/media/demo', fetchImpl }), 2);
  assert.equal(seen.length, 4);

  const none = [];
  assert.equal(await warmThumbnails({ products }, { mediaBase: '/media/demo', fetchImpl: async (u) => { none.push(u); return { ok: true, blob: async () => null }; }, isOnline: () => false }), 0);
  assert.equal(none.length, 0);
});
