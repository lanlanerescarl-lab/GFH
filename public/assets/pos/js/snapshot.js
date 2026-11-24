// The terminal snapshot: prices, stock and rules for one terminal. The screens only ever read it from here, from memory.
//
// load() downloads it through api.js (so a session timeout is handled like every other call) and keeps a copy in the
// local store with the time and the server's etag. When the device is offline, or the download fails for any reason,
// load() uses the stored copy instead and says so through info(). With no stored copy and no connection it throws the
// network error with a plain message. Selling is never blocked by the age of the copy; the banner only reports it.
//
// The API sends an "etag" inside the snapshot (a hash of its content) but does not answer conditional requests, so the
// whole snapshot is downloaded each time and the etag is used to tell whether anything changed.

import { ApiError } from './api.js';
import { indexProducts } from './catalog.js';

export const SNAPSHOT_STORE = 'snapshot';

const NOTHING_SAVED = 'There is no saved price list on this device and no connection to load one. Connect to the internet and try again.';

/**
 * @param {{snapshot: (terminalId: string) => Promise<object>}} api
 * @param {object} [options]
 * @param {object|null} [options.store] the store interface; without it nothing is kept between page loads
 * @param {() => number} [options.now] milliseconds since 1970
 * @param {(data: object) => void} [options.onDownloaded] told after a fresh download (the app warms the thumbnail cache here)
 */
export function createSnapshotStore(api, { store = null, now = () => Date.now(), onDownloaded = () => {} } = {}) {
  let current = null;
  let byVariant = new Map();
  let information = { source: null, downloadedAt: null, etag: null, changed: false, error: null };

  function adopt(data) {
    current = data;
    byVariant = indexProducts(data.products);
  }

  async function stored(terminalId) {
    if (store === null) return null;
    try {
      return (await store.get(SNAPSHOT_STORE, terminalId)) ?? null;
    } catch {
      return null;
    }
  }

  return {
    /**
     * Downloads the snapshot of a terminal, or uses the stored copy when that is not possible. Returns the snapshot.
     * Throws only when there is nothing to use.
     */
    async load(terminalId) {
      const before = await stored(terminalId);
      try {
        const data = await api.snapshot(terminalId);
        adopt(data);
        const downloadedAt = now();
        information = { source: 'network', downloadedAt, etag: data.etag ?? null, changed: before === null || before.etag !== (data.etag ?? null), error: null };
        if (store !== null) {
          try {
            await store.put(SNAPSHOT_STORE, terminalId, { terminal_id: terminalId, snapshot: data, etag: data.etag ?? null, downloaded_at: downloadedAt });
          } catch {
            // The copy could not be kept (storage full or blocked). The POS works online from memory; offline starts will not work.
            information = { ...information, saveFailed: true };
          }
        }
        onDownloaded(data);
        return data;
      } catch (error) {
        if (before === null) {
          throw error instanceof ApiError && error.network ? new ApiError({ network: true, message: NOTHING_SAVED }) : error;
        }
        adopt(before.snapshot);
        information = { source: 'stored', downloadedAt: before.downloaded_at, etag: before.etag ?? null, changed: false, error };
        return before.snapshot;
      }
    },

    /** The stored snapshot of a terminal without touching the network (the terminal and the data), or null. */
    stored,

    clear() {
      current = null;
      byVariant = new Map();
      information = { source: null, downloadedAt: null, etag: null, changed: false, error: null };
    },

    /** Where the current snapshot came from: {source: 'network'|'stored'|null, downloadedAt (ms), etag, changed, error}. */
    info() {
      return { ...information };
    },

    /** The snapshot, or null before the first load. */
    get() {
      return current;
    },

    products() {
      return current?.products ?? [];
    },

    /** Products by variant id, as the ticket and pricing need them. */
    productsByVariant() {
      return byVariant;
    },
  };
}
