// Fills the header's cart count and keeps it right when the cart changes here or in another tab.
import { lineCount } from './cart.js';

export function initCartBadge(store, badges) {
  const show = () => {
    const count = lineCount(store.read());
    badges.forEach((badge) => {
      const number = badge.querySelector('[data-cart-number]');
      if (number) {
        number.textContent = String(count);
      }
      badge.hidden = count === 0;
    });
  };
  show();
  store.subscribe(show);
}
