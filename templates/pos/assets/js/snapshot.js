// The terminal snapshot, held in memory. It is loaded through api.js (so a session timeout is handled the same way as
// for every other call) and read by the screens. Batch 7.2 will also keep a copy in IndexedDB for offline starts;
// the screens only ever read from here.

import { indexProducts } from './catalog.js';

/** @param {{snapshot: (terminalId: string) => Promise<object>}} api */
export function createSnapshotStore(api) {
  let current = null;
  let byVariant = new Map();

  return {
    /** Downloads the snapshot of a terminal and keeps it. Throws what the API throws. */
    async load(terminalId) {
      const data = await api.snapshot(terminalId);
      current = data;
      byVariant = indexProducts(data.products);
      return data;
    },

    clear() {
      current = null;
      byVariant = new Map();
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
