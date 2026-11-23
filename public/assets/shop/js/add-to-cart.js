// The product page's Add to cart form. It adds to the browser's cart (cart.js decides whether the quantity is allowed),
// says so politely to screen readers, and offers a link to the cart. Prices and stock are not touched here.
import { addToCart } from './cart.js';
import { el } from './dom.js';

export function initAddToCart(form, store) {
  const quantity = form.querySelector('[data-quantity]');
  const status = form.querySelector('[data-cart-status]');
  const cartUrl = form.dataset.cartUrl ?? '';
  if (!quantity || !status) {
    return;
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const rules = { decimal: form.dataset.decimal === '1', step: quantity.step || '1', min: quantity.min || '1' };
    const result = addToCart(store.read(), form.dataset.variantId ?? '', quantity.value, rules);

    status.classList.toggle('is-error', !result.ok);
    if (!result.ok) {
      status.replaceChildren(document.createTextNode(result.message));
      quantity.setAttribute('aria-invalid', 'true');
      return;
    }
    quantity.removeAttribute('aria-invalid');

    if (!store.write(result.lines)) {
      status.classList.add('is-error');
      status.replaceChildren(document.createTextNode('Your browser would not keep the cart. Check that it allows this site to store data.'));
      return;
    }

    const message = 'Added to cart. ';
    status.replaceChildren(document.createTextNode(message), el('a', { href: cartUrl, text: 'View cart' }));
  });
}
