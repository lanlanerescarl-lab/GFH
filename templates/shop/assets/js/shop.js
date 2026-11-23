// Shop entry module. It only wires up what a page contains (by data attributes); every page works without it.
import { initGallery } from './gallery.js';
import { initVariantSelector } from './variant-selector.js';
import { initFilterDrawer } from './filter-drawer.js';
import { initAddToCart } from './add-to-cart.js';
import { createApi } from './api.js';
import { initCartBadge } from './cart-badge.js';
import { initCartPage } from './cart-page.js';
import { createCartStore } from './cart-store.js';
import { initCheckout } from './checkout.js';
import { readConfig } from './dom.js';
import { initPrintButton } from './print-button.js';

// A photo that cannot be loaded is swapped for the same inline placeholder a product without photos shows.
const swapForPlaceholder = (image) => {
  const template = document.getElementById('shop-placeholder-template');
  if (!template || !image.isConnected || !image.closest('.media-frame')) {
    return;
  }
  image.replaceWith(template.content.cloneNode(true));
};

// "error" does not bubble, so listen while it travels down (capture) to catch every image.
document.addEventListener('error', (event) => {
  if (event.target instanceof HTMLImageElement) {
    swapForPlaceholder(event.target);
  }
}, true);

// Images that failed before this script ran.
document.querySelectorAll('.media-frame img').forEach((image) => {
  if (image.complete && image.naturalWidth === 0) {
    swapForPlaceholder(image);
  }
});

document.querySelectorAll('[data-gallery]').forEach(initGallery);
document.querySelectorAll('[data-variant-form]').forEach(initVariantSelector);
document.querySelectorAll('[data-filter-form]').forEach(initFilterDrawer);

// Cart, sign-in state and the customer pages (Batch 6.2). The cart lives in this browser (cart.js); the cart and
// checkout pages ask the server's JSON API for prices and to place the order. The settings come from <body>.
const config = readConfig();
const store = createCartStore(config.slug);
const api = createApi({ baseUrl: config.apiBase, csrfToken: config.csrf });

initCartBadge(store, [...document.querySelectorAll('[data-cart-badge]')]);
document.querySelectorAll('[data-add-to-cart]').forEach((form) => initAddToCart(form, store));
document.querySelectorAll('[data-cart-page]').forEach((root) => initCartPage(root, { store, api }));
document.querySelectorAll('[data-checkout-page]').forEach((root) => initCheckout(root, { store, api }));
document.querySelectorAll('[data-print]').forEach(initPrintButton);

// A form's error summary takes focus on load, so a keyboard or screen reader user lands on what went wrong.
document.querySelector('[data-focus-on-load]')?.focus();
