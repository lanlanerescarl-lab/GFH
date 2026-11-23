// The cart page. It reads the cart from the browser, asks the server to price it (on load and after every change) and
// draws only what the server answers. A line the server reports as unavailable or invalid is flagged in words and can
// be removed; Continue to checkout is off while a price request runs, has failed, or any line is flagged.
import { ApiError, fieldErrors } from './api.js';
import { removeLine, setQuantity } from './cart.js';
import { lineTitle, renderSummary, unitPriceText } from './cart-view.js';
import { announce, el } from './dom.js';
import { formatPeso, formatQuantity } from './format.js';

export function initCartPage(root, { store, api }) {
  const part = (name) => root.querySelector(`[data-cart-${name}]`);
  const live = part('live');
  const emptyBox = part('empty');
  const content = part('content');
  const linesBox = part('lines');
  const summaryBox = part('summary');
  const messageBox = part('message');
  const title = root.querySelector('#cart-title');
  const button = root.querySelector('[data-checkout-button]');
  const checkoutUrl = root.dataset.checkoutUrl ?? '';

  let sequence = 0;
  let state = { status: 'idle', priced: null, problems: {}, message: '' };
  let note = '';
  const quantityErrors = new Map();

  button.addEventListener('click', () => {
    if (!button.disabled) {
      window.location.assign(checkoutUrl);
    }
  });

  async function refresh() {
    const lines = store.read();
    if (lines.length === 0) {
      sequence += 1;
      state = { status: 'empty', priced: null, problems: {}, message: '' };
      draw(lines);
      return;
    }

    const mine = (sequence += 1);
    state = { ...state, status: 'loading' };
    draw(lines);
    try {
      const priced = await api.post('/cart/price', { items: lines });
      if (mine !== sequence) {
        return;
      }
      state = { status: 'ok', priced, problems: {}, message: '' };
      announce(live, `${note}Cart updated. Total ${formatPeso(priced.total)}.`);
      note = '';
    } catch (error) {
      if (mine !== sequence) {
        return;
      }
      if (error instanceof ApiError && error.isValidation) {
        state = { status: 'problems', priced: null, problems: fieldErrors(error), message: error.message };
        announce(live, `${note}Some products in your cart need attention.`);
      } else {
        const message = error instanceof ApiError ? error.message : 'Something went wrong. Please try again.';
        state = { status: 'error', priced: null, problems: {}, message };
        announce(live, `${note}${message}`);
      }
      note = '';
    }
    draw(store.read());
  }

  function remove(variantId, name) {
    note = name ? `Removed ${name}. ` : 'Removed a product. ';
    store.write(removeLine(store.read(), variantId));
    title?.focus();
  }

  function quantityControl(line, index) {
    const id = `cart-qty-${index}`;
    const helpId = `${id}-help`;
    const rules = { decimal: line.allow_decimal_qty === true, step: line.qty_step, min: line.min_qty };
    const input = el('input', {
      id,
      type: 'number',
      inputmode: rules.decimal ? 'decimal' : 'numeric',
      step: formatQuantity(line.qty_step),
      min: formatQuantity(line.min_qty),
      value: formatQuantity(line.quantity),
      'aria-describedby': helpId,
    });
    const help = el('p', { class: 'field-error', id: helpId, role: 'alert', text: quantityErrors.get(line.variant_id) ?? '' });
    if (quantityErrors.has(line.variant_id)) {
      input.setAttribute('aria-invalid', 'true');
    }
    input.addEventListener('change', () => {
      const result = setQuantity(store.read(), line.variant_id, input.value, rules);
      if (!result.ok) {
        quantityErrors.set(line.variant_id, result.message);
        help.textContent = result.message;
        input.setAttribute('aria-invalid', 'true');
        return;
      }
      quantityErrors.delete(line.variant_id);
      store.write(result.lines);
    });
    return el('div', { class: 'cart-line__qty field' }, el('label', { for: id, text: 'Quantity' }), input, help);
  }

  function pricedLine(line, index) {
    const name = lineTitle(line);
    return el('li', { class: 'cart-line' },
      el('div', { class: 'cart-line__info' },
        el('p', { class: 'cart-line__name', text: line.product_name }),
        line.variant_name && line.variant_name !== line.product_name ? el('p', { class: 'cart-line__option', text: line.variant_name }) : null,
        el('p', { class: 'cart-line__price', text: unitPriceText(line) }),
        line.is_made_to_order ? el('p', { class: 'cart-line__flag', text: 'Made to order' }) : null),
      quantityControl(line, index),
      el('p', { class: 'cart-line__total' }, el('span', { class: 'visually-hidden', text: 'Line total: ' }), formatPeso(line.line_total)),
      removeButton(line.variant_id, name));
  }

  function removeButton(variantId, name) {
    const button = el('button', { class: 'button button--secondary cart-line__remove', type: 'button', text: 'Remove', 'aria-label': name ? `Remove ${name}` : 'Remove this product' });
    button.addEventListener('click', () => remove(variantId, name));
    return button;
  }

  function flaggedLine(stored, message) {
    return el('li', { class: 'cart-line cart-line--flagged' },
      el('div', { class: 'cart-line__info' },
        el('p', { class: 'cart-line__name', text: 'A product in your cart needs attention' }),
        el('p', { class: 'cart-line__flag', role: 'alert', text: message })),
      removeButton(stored.variant_id, ''));
  }

  function showMessage(text, retry) {
    messageBox.replaceChildren(el('p', { text }));
    if (retry) {
      const again = el('button', { class: 'button button--secondary', type: 'button', text: 'Try again' });
      again.addEventListener('click', refresh);
      messageBox.append(again);
    }
    messageBox.hidden = false;
  }

  function draw(lines) {
    const isEmpty = lines.length === 0;
    emptyBox.hidden = !isEmpty;
    content.hidden = isEmpty;
    messageBox.hidden = true;
    messageBox.replaceChildren();
    root.setAttribute('aria-busy', state.status === 'loading' ? 'true' : 'false');
    button.disabled = state.status !== 'ok';
    if (isEmpty) {
      return;
    }

    if (state.status === 'ok') {
      linesBox.replaceChildren(el('ul', { class: 'cart-lines' }, state.priced.lines.map(pricedLine)));
      renderSummary(summaryBox, state.priced);
    } else if (state.status === 'problems') {
      const flagged = lines.filter((line) => state.problems[`items.${line.variant_id}`] !== undefined);
      linesBox.replaceChildren(el('ul', { class: 'cart-lines' }, flagged.map((line) => flaggedLine(line, state.problems[`items.${line.variant_id}`]))));
      summaryBox.replaceChildren();
      showMessage(flagged.length > 0 ? 'Remove the products marked below and your prices will appear.' : state.message);
      if (flagged.length === 0) {
        const emptyCart = el('button', { class: 'button button--secondary', type: 'button', text: 'Empty the cart' });
        emptyCart.addEventListener('click', () => store.write([]));
        messageBox.append(emptyCart);
      }
    } else if (state.status === 'error') {
      linesBox.replaceChildren();
      summaryBox.replaceChildren();
      showMessage(state.message, true);
    } else if (state.priced === null) {
      linesBox.replaceChildren(el('p', { class: 'help', text: 'Checking prices…' }));
    }
  }

  store.subscribe(refresh);
  refresh();
}
