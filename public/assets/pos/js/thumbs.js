// Warms the thumbnail cache. When a snapshot downloads, every product picture it mentions is requested once in the
// background. The service worker keeps each answer (up to 400, oldest dropped), so the sell screen shows pictures
// offline. A failure is ignored and nothing here ever blocks a screen. Without a service worker the requests are
// harmless: the browser just fetches pictures it would fetch anyway.

export const MAX_THUMBNAILS = 400;
const AT_ONCE = 4;

/** The picture addresses for a snapshot's products: one per distinct media id, at most MAX_THUMBNAILS, first products first. */
export function thumbnailUrls(products, mediaBase, limit = MAX_THUMBNAILS) {
  const ids = [...new Set(products.map((product) => product.media_id).filter((id) => typeof id === 'string' && id !== ''))];
  return ids.slice(0, limit).map((id) => `${mediaBase}/${encodeURIComponent(id)}?thumb=1`);
}

/**
 * @param {object} snapshot
 * @param {object} options
 * @param {string} options.mediaBase for example "/media/demo"
 * @param {typeof fetch} [options.fetchImpl]
 * @param {() => boolean} [options.isOnline]
 * @returns {Promise<number>} how many pictures were fetched (tests use it; the app ignores it)
 */
export async function warmThumbnails(snapshot, { mediaBase, fetchImpl = (...args) => globalThis.fetch(...args), isOnline = () => true }) {
  const queue = thumbnailUrls(snapshot.products ?? [], mediaBase);
  let fetched = 0;

  async function worker() {
    for (let url = queue.shift(); url !== undefined; url = queue.shift()) {
      if (!isOnline()) return;
      try {
        const response = await fetchImpl(url, { credentials: 'same-origin' });
        if (response.ok) {
          await response.blob();
          fetched += 1;
        }
      } catch {
        // Ignored: a missing picture only means a plain tile.
      }
    }
  }

  await Promise.all(Array.from({ length: AT_ONCE }, worker));
  return fetched;
}
