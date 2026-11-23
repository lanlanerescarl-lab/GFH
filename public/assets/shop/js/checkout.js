// The checkout page. It reads the cart from the browser, asks the server for the checkout options and the price, builds
// the form, re-prices when the choice of pickup or delivery changes, and places the order through the JSON API.
// No payment is taken here: the page says so, and the order is placed unpaid.
import { ApiError, fieldErrors } from './api.js';
import { renderLineList, renderSummary } from './cart-view.js';
import { addressPrefill, checkoutBody, pricingBody, shortStockNotes, splitErrors } from './checkout-model.js';
import { announce, clear, el } from './dom.js';
import { formatPeso, formatQuantity, isPositiveAmount, paymentMethodLabel } from './format.js';

export function initCheckout(root, { store, api }) {
  const part = (name) => root.querySelector(`[data-checkout-${name}]`);
  const live = part('live');
  const loading = part('loading');
  const content = part('content');
  const formBox = part('form');
  const summaryBox = part('summary');
  const linesBox = part('lines');
  const paymentBox = part('payment');
  const errorBox = part('errors');
  const cartUrl = root.dataset.cartUrl ?? '';
  const ordersBase = root.dataset.ordersBase ?? '';
  const signInUrl = root.dataset.signInUrl ?? '';

  const lines = store.read();
  if (lines.length === 0) {
    window.location.replace(cartUrl);
    return;
  }

  let options = null;
  let priced = null;
  let pricedOk = false;
  let busy = false;
  let sequence = 0;
  const controls = {};
  const fieldBoxes = {};

  // ---- form building ----

  function field(name, label, { type = 'text', autocomplete = '', required = false, maxlength = 150, inputmode = '' } = {}) {
    const errorId = `checkout-${name}-error`;
    const input = el('input', { id: `checkout-${name}`, name, type, autocomplete: autocomplete || null, maxlength, inputmode: inputmode || null, 'aria-required': required ? 'true' : null, 'aria-describedby': errorId });
    const error = el('p', { class: 'field-error', id: errorId });
    fieldBoxes[name] = { input, error };
    return el('div', { class: 'field' }, el('label', { for: input.id, text: required ? `${label} (required)` : label }), input, error);
  }

  function buildForm() {
    const methods = [];
    if (options.pickup_branches.length > 0) {
      methods.push({ key: 'pickup', label: 'Pickup at a branch' });
    }
    if (options.delivery_available === true) {
      methods.push({ key: 'delivery', label: 'Delivery to my address' });
    }
    if (methods.length === 0) {
      formBox.replaceChildren(el('p', { class: 'alert', text: 'This shop cannot take online orders right now. Please try again later or contact the shop.' }));
      return;
    }

    const radios = methods.map((method, index) => {
      const id = `checkout-method-${method.key}`;
      const input = el('input', { type: 'radio', name: 'method', id, value: method.key, checked: index === 0 });
      controls[`method-${method.key}`] = input;
      return el('div', { class: 'choice' }, input, el('label', { for: id, text: method.label }));
    });
    const methodError = el('p', { class: 'field-error', id: 'checkout-method-error' });
    fieldBoxes.method = { input: radios[0].querySelector('input'), error: methodError };

    const branchSelect = el('select', { id: 'checkout-branch_id', name: 'branch_id', 'aria-describedby': 'checkout-branch_id-error' },
      options.pickup_branches.length === 1 ? null : el('option', { value: '', text: 'Choose a branch' }),
      options.pickup_branches.map((branch) => el('option', { value: branch.id, text: [branch.name, branch.address, branch.city].filter((part) => typeof part === 'string' && part !== '').join(', ') })));
    const branchError = el('p', { class: 'field-error', id: 'checkout-branch_id-error' });
    fieldBoxes.branch_id = { input: branchSelect, error: branchError };
    controls.branch = branchSelect;

    const pickupPanel = el('div', { class: 'checkout-panel', 'data-panel': 'pickup' },
      el('div', { class: 'field' }, el('label', { for: 'checkout-branch_id', text: 'Pickup branch (required)' }), branchSelect, branchError));
    const deliveryPanel = el('div', { class: 'checkout-panel', 'data-panel': 'delivery' },
      field('recipient', 'Recipient name', { autocomplete: 'name', maxlength: 120 }),
      field('phone', 'Phone number', { type: 'tel', autocomplete: 'tel', maxlength: 20, inputmode: 'tel' }),
      field('line1', 'House number and street', { autocomplete: 'address-line1', required: true }),
      field('barangay', 'Barangay', { autocomplete: 'address-level3', maxlength: 80 }),
      field('city', 'City or municipality', { autocomplete: 'address-level2', required: true, maxlength: 80 }),
      field('province', 'Province', { autocomplete: 'address-level1', required: true, maxlength: 80 }),
      field('postal_code', 'Postal code', { autocomplete: 'postal-code', maxlength: 10, inputmode: 'numeric' }));
    controls.pickupPanel = pickupPanel;
    controls.deliveryPanel = deliveryPanel;

    const submit = el('button', { class: 'button checkout__submit', type: 'submit', text: 'Place order', disabled: true });
    controls.submit = submit;

    formBox.replaceChildren(
      el('fieldset', { class: 'checkout-fieldset' }, el('legend', { text: 'How would you like to receive your order?' }), radios, methodError),
      pickupPanel,
      deliveryPanel,
      submit);
    syncPanels();

    formBox.addEventListener('change', (event) => {
      if (event.target.name === 'method') {
        syncPanels();
        reprice();
      } else if (event.target.name === 'branch_id' || (currentMethod() === 'delivery' && fieldBoxes[event.target.name])) {
        reprice();
      }
    });
    formBox.addEventListener('submit', place);
  }

  function currentMethod() {
    return formBox.querySelector('input[name="method"]:checked')?.value ?? 'pickup';
  }

  function syncPanels() {
    const delivery = currentMethod() === 'delivery';
    controls.pickupPanel.hidden = delivery;
    controls.deliveryPanel.hidden = !delivery;
  }

  function values() {
    const address = {};
    for (const name of ['recipient', 'phone', 'line1', 'barangay', 'city', 'province', 'postal_code']) {
      address[name] = fieldBoxes[name]?.input.value ?? '';
    }
    return { method: currentMethod(), branch_id: controls.branch?.value ?? '', address };
  }

  function prefill(addresses) {
    const saved = addressPrefill(addresses);
    for (const [name, value] of Object.entries(saved)) {
      const input = fieldBoxes[name]?.input;
      if (input && input.value === '' && value !== '') {
        input.value = value;
      }
    }
  }

  // ---- pricing and the summary ----

  function drawSummary() {
    renderLineList(linesBox, priced.lines);
    renderSummary(summaryBox, priced, { showDeposit: true });
    const methods = (options.payment_methods ?? []).map((key) => paymentMethodLabel(key));
    const children = [
      el('h2', { class: 'checkout-payment__title', text: 'Payment' }),
      el('p', { text: 'This shop does not take payment online. Your order is placed unpaid, and the shop will confirm with you how to pay.' }),
    ];
    if (methods.length > 0) {
      children.push(el('p', { text: `Accepted payment methods: ${methods.join(', ')}.` }));
    }
    if (isPositiveAmount(priced.deposit_due)) {
      children.push(el('p', { class: 'summary__deposit', text: `A deposit of ${formatPeso(priced.deposit_due)} is due for this order.` }));
    }
    paymentBox.replaceChildren(...children);
  }

  async function reprice() {
    const mine = (sequence += 1);
    pricedOk = false;
    updateSubmit();
    summaryBox.setAttribute('aria-busy', 'true');
    try {
      const answer = await api.post('/cart/price', pricingBody(store.read(), values()));
      if (mine !== sequence) {
        return;
      }
      priced = answer;
      pricedOk = true;
      drawSummary();
      announce(live, `Order summary updated. Total ${formatPeso(priced.total)}.`);
    } catch (error) {
      if (mine !== sequence) {
        return;
      }
      pricedOk = false;
      const message = error instanceof ApiError && error.isValidation
        ? 'Some products in your cart need attention before you can check out.'
        : (error instanceof ApiError ? error.message : 'We could not price your order. Please try again.');
      summaryBox.replaceChildren(el('p', { class: 'alert', role: 'alert', text: message }), el('a', { class: 'button button--secondary', href: cartUrl, text: 'Back to the cart' }));
      linesBox.replaceChildren();
      paymentBox.replaceChildren();
    } finally {
      if (mine === sequence) {
        summaryBox.setAttribute('aria-busy', 'false');
        updateSubmit();
      }
    }
  }

  function updateSubmit() {
    if (controls.submit) {
      controls.submit.disabled = busy || !pricedOk;
    }
  }

  // ---- placing the order ----

  function clearErrors() {
    for (const { input, error } of Object.values(fieldBoxes)) {
      error.textContent = '';
      input.removeAttribute('aria-invalid');
    }
    errorBox.hidden = true;
    clear(errorBox);
  }

  function showProblems(summaryItems, intro) {
    const list = el('ul', {}, summaryItems.map((item) => el('li', {}, item.field && fieldBoxes[item.field]
      ? el('a', { href: `#${fieldBoxes[item.field].input.id}`, text: item.message })
      : item.node ?? item.message)));
    errorBox.replaceChildren(el('h2', { id: 'checkout-errors-title', text: 'There is a problem' }), intro ? el('p', { text: intro }) : null, list);
    errorBox.hidden = false;
    errorBox.focus();
  }

  async function place(event) {
    event.preventDefault();
    if (busy || !pricedOk) {
      return;
    }
    busy = true;
    updateSubmit();
    controls.submit.textContent = 'Placing your order…';
    clearErrors();

    try {
      const order = await api.post('/checkout', checkoutBody(store.read(), values()));
      store.write([]);
      window.location.assign(`${ordersBase}${encodeURIComponent(order.id)}?placed=1`);
      return;
    } catch (error) {
      busy = false;
      controls.submit.textContent = 'Place order';
      updateSubmit();
      handleFailure(error);
    }
  }

  function handleFailure(error) {
    if (error instanceof ApiError && error.isValidation) {
      const { fields, general } = splitErrors(fieldErrors(error));
      const items = [];
      for (const [name, message] of Object.entries(fields)) {
        const box = fieldBoxes[name];
        if (box) {
          box.error.textContent = message;
          box.input.setAttribute('aria-invalid', 'true');
        }
        items.push({ field: name, message });
      }

      let intro = '';
      for (const message of general) {
        const notes = shortStockNotes(message, priced?.lines);
        if (notes !== null) {
          intro = 'Nothing was ordered, and your cart is kept. Update your cart and try again.';
          for (const note of notes) {
            items.push({ message: `${note.name}: only ${formatQuantity(note.available)} available.` });
          }
          items.push({ node: el('a', { class: 'button button--secondary', href: cartUrl, text: 'Update cart' }) });
        } else {
          items.push({ message });
        }
      }
      showProblems(items.length > 0 ? items : [{ message: error.message }], intro);
      return;
    }

    if (error instanceof ApiError && error.status === 401) {
      showProblems([{ message: 'Your sign-in ended.' }, { node: el('a', { href: signInUrl, text: 'Sign in again' }) }], 'Your cart is kept.');
      return;
    }
    showProblems([{ message: error instanceof ApiError ? error.message : 'Something went wrong. Please try again.' }], 'Your order was not placed.');
  }

  // ---- start ----

  (async () => {
    try {
      options = await api.get('/checkout/options');
    } catch (error) {
      loading.textContent = error instanceof ApiError ? error.message : 'We could not load checkout. Please try again.';
      return;
    }
    options.pickup_branches = Array.isArray(options.pickup_branches) ? options.pickup_branches : [];
    loading.hidden = true;
    content.hidden = false;
    buildForm();

    const addresses = api.get('/account/addresses').then(prefill, () => {});
    if (controls.submit) {
      await Promise.all([reprice(), addresses]);
      if (controls.deliveryPanel && currentMethod() === 'delivery') {
        reprice();
      }
    }
  })();
}
