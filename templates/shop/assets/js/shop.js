// Shop entry module. It only wires up what a page contains (by data attributes); every page works without it.
import { initGallery } from './gallery.js';
import { initVariantSelector } from './variant-selector.js';
import { initFilterDrawer } from './filter-drawer.js';

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

// The Add to cart form exists but is disabled in this batch; make sure nothing can submit it.
document.querySelectorAll('[data-add-to-cart]').forEach((form) => {
  form.addEventListener('submit', (event) => event.preventDefault());
});
