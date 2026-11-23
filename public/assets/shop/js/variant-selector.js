// Variant selector. The server already rendered the default variant (or the one in ?variant=), so the page is
// complete without this module. With it, choosing another variant updates the price, SKU, availability, price tiers
// and the quantity rules in place. All the values come from data attributes on the radio buttons.

export function initVariantSelector(form) {
  const radios = [...form.querySelectorAll('input[name="variant"]')];
  if (radios.length < 2) {
    return;
  }

  const price = document.querySelector('[data-variant-price]');
  const sku = document.querySelector('[data-variant-sku]');
  const availability = document.querySelector('[data-variant-availability]');
  const tierBlocks = [...document.querySelectorAll('[data-variant-tiers]')];
  const cart = document.querySelector('[data-add-to-cart]');
  const quantity = cart?.querySelector('[data-quantity]');
  const quantityHelp = cart?.querySelector('[data-quantity-help]');

  const apply = (radio) => {
    const data = radio.dataset;

    if (price) {
      price.textContent = data.price;
    }
    if (sku) {
      sku.textContent = data.sku;
    }
    if (availability) {
      availability.textContent = data.inStock === '1' ? 'In stock' : 'Out of stock';
    }
    tierBlocks.forEach((block) => {
      block.hidden = block.dataset.variantTiers !== radio.value;
    });

    if (cart) {
      cart.dataset.variantId = radio.value;
      cart.dataset.decimal = data.decimal === '1' ? '1' : '0';
    }
    if (quantity) {
      // Each variant has its own step and minimum; start again from its minimum.
      quantity.step = data.step;
      quantity.min = data.min;
      quantity.value = data.min;
      quantity.inputMode = data.decimal === '1' ? 'decimal' : 'numeric';
    }
    if (quantityHelp) {
      quantityHelp.textContent = `${data.decimal === '1' ? `Steps of ${data.step}. ` : ''}Minimum ${data.min}.`;
    }

    // Keep the choice if the page is reloaded or the address is shared.
    const address = new URL(window.location.href);
    address.searchParams.set('variant', radio.value);
    window.history.replaceState(null, '', address);
  };

  radios.forEach((radio) => {
    radio.addEventListener('change', () => {
      if (radio.checked) {
        apply(radio);
      }
    });
  });
}
