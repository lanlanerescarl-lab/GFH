// The cart as the page sees it: storage plus change notices. Rules and the stored format live in cart.js.
// Same-tab changes are announced with a "gfh:cart-changed" event; another tab's changes arrive through "storage".
import { loadCart, saveCart, storageKey } from './cart.js';

export const CART_CHANGED = 'gfh:cart-changed';

/** The browser's localStorage, or null when it is blocked (private modes, strict settings). */
function browserStorage() {
  try {
    const storage = window.localStorage;
    storage.getItem('gfh-cart:probe');
    return storage;
  } catch {
    return null;
  }
}

/** A stand-in with the same three methods, so the cart still works for this page view when storage is blocked. */
function memoryStorage() {
  const values = new Map();
  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };
}

export function createCartStore(slug, storage = browserStorage() ?? memoryStorage()) {
  return {
    read: () => loadCart(storage, slug),
    /** Saves the cart and tells this page's other modules. Returns false if the browser refused. */
    write(lines) {
      const saved = saveCart(storage, slug, lines);
      window.dispatchEvent(new CustomEvent(CART_CHANGED));
      return saved;
    },
    /** Calls back after any change to this organization's cart, here or in another tab. */
    subscribe(callback) {
      const onStorage = (event) => {
        if (event.key === null || event.key === storageKey(slug)) {
          callback();
        }
      };
      window.addEventListener(CART_CHANGED, callback);
      window.addEventListener('storage', onStorage);
      return () => {
        window.removeEventListener(CART_CHANGED, callback);
        window.removeEventListener('storage', onStorage);
      };
    },
  };
}
