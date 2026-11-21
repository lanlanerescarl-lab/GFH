// Screen: sell. A product grid with photos, an always-ready search and barcode box, and the ticket.
// This file draws and routes events. The rules live in the pure modules: ticket.js (lines and quantities),
// pricing.js (the preview total), barcode.js and catalog.js (lookups).
//
// The search box keeps the keyboard: a scanner types digits and Enter, so after every tap the focus goes back to it,
// and a letter or digit typed while nothing else is being edited is sent to it. It never takes the focus back from
// someone moving through the buttons with the keyboard (Tab or Enter on a button leaves the focus where it is).

import { h, $, clear, append, uid } from './dom.js';
import { icon, productPlaceholder } from './icons.js';
import { formatPeso, formatQuantity, parseQuantity, parseMoney } from './money.js';
import { priceSale } from './pricing.js';
import { emptyTicket, addProduct, adjustQuantity, setQuantity, removeLine, pricingEntries, quantityRules } from './ticket.js';
import { searchProducts, stockStatus, oversellNote } from './catalog.js';
import { findByBarcode, normalizeScan } from './barcode.js';
import { confirmDialog } from './dialog.js';
import { openDiscountDialog } from './discount.js';

const GRID_LIMIT = 120;

export function createSellScreen(ctx) {
  const root = $('#screen-sell');
  let built = false;
  let refs = null;
  let query = '';
  let priced = null;
  let messageTimer = null;

  function build() {
    const searchId = uid('search');
    const search = h('input', {
      id: searchId, class: 'pos-input pos-search', type: 'search', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false',
      enterkeyhint: 'search', placeholder: 'Search or scan a barcode',
    });
    const flashBox = h('p', { class: 'pos-flash', role: 'status', 'aria-live': 'polite' });
    const grid = h('ul', { class: 'pos-grid', 'aria-label': 'Products' });
    const count = h('p', { class: 'pos-muted pos-grid__count' });

    const handle = h('button', { class: 'pos-ticket__handle', type: 'button', 'aria-expanded': 'false', 'aria-controls': 'pos-ticket' });
    const lines = h('ul', { class: 'pos-lines', 'aria-label': 'Ticket lines' });
    const empty = h('p', { class: 'pos-ticket__empty pos-muted' }, 'The ticket is empty. Tap a product or scan a barcode.');
    const totals = h('dl', { class: 'pos-totals', 'aria-live': 'polite', 'aria-atomic': 'true' });
    const discountButton = h('button', { class: 'pos-button pos-button--quiet', type: 'button', onClick: async () => { await openDiscountDialog(ctx); focusSearch(); } }, 'Discount');
    const clearButton = h('button', { class: 'pos-button pos-button--quiet', type: 'button', onClick: clearTicket }, 'Clear');
    const pay = h('button', { class: 'pos-button pos-button--pay pos-button--pay-large', type: 'button', onClick: openPay }, 'Pay');
    const lineMessage = h('p', { class: 'pos-field-error', role: 'alert', hidden: true });

    const ticket = h('aside', { class: 'pos-ticket', id: 'pos-ticket', 'aria-label': 'Ticket' },
      handle,
      h('div', { class: 'pos-ticket__panel' },
        h('div', { class: 'pos-ticket__head' }, h('h2', {}, 'Ticket'), h('span', { class: 'pos-ticket__tools' }, discountButton, clearButton)),
        h('div', { class: 'pos-ticket__scroll' }, empty, lines, lineMessage),
        h('div', { class: 'pos-ticket__foot' }, totals, pay)));

    handle.addEventListener('click', () => {
      const open = !ticket.classList.contains('is-open');
      ticket.classList.toggle('is-open', open);
      handle.setAttribute('aria-expanded', String(open));
    });

    const refresh = h('button', { class: 'pos-button pos-button--quiet', type: 'button', onClick: refreshProducts }, 'Refresh prices and stock');

    const catalog = h('div', { class: 'pos-catalog' },
      h('div', { class: 'pos-toolbar' },
        h('label', { class: 'visually-hidden', for: searchId }, 'Search products or scan a barcode'),
        h('span', { class: 'pos-search__wrap' }, icon('search'), search),
        refresh),
      flashBox, count, grid);

    search.addEventListener('input', () => { query = search.value; drawGrid(); });
    search.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        scan(search.value);
      }
    });

    append(clear(root), [h('h1', { class: 'visually-hidden' }, 'Sell'), h('div', { class: 'pos-sell' }, catalog, ticket)]);
    refs = { search, flash: flashBox, grid, count, handle, ticket, lines, empty, totals, pay, discountButton, clearButton, lineMessage };
    built = true;
  }

  // ---------------------------------------------------------------- focus and keys

  function focusSearch() {
    refs?.search.focus({ preventScroll: true });
  }

  function onKeydown(event) {
    if (document.querySelector('dialog[open]') || event.ctrlKey || event.metaKey || event.altKey) return;
    const target = event.target;
    const editing = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement || target?.isContentEditable;
    if (editing || !/^[0-9A-Za-z]$/.test(event.key)) return;
    // A scanner or a typist started without the box focused: the character goes into the search box.
    event.preventDefault();
    refs.search.focus();
    refs.search.value += event.key;
    refs.search.dispatchEvent(new Event('input'));
  }

  /** After a click: back to the search box for a pointer; stay on the control for the keyboard (a click with detail 0). */
  function afterClick(event) {
    if (event.detail === 0) return;
    focusSearch();
  }

  // ---------------------------------------------------------------- messages

  function flash(text, tone = 'ok') {
    refs.flash.textContent = text;
    refs.flash.className = `pos-flash pos-flash--${tone}`;
    clearTimeout(messageTimer);
    if (tone === 'ok') messageTimer = setTimeout(() => { refs.flash.textContent = ''; refs.flash.className = 'pos-flash'; }, 4000);
  }

  // ---------------------------------------------------------------- the product grid

  function drawGrid() {
    const all = ctx.snapshot.products();
    const found = searchProducts(all, query);
    const shown = found.slice(0, GRID_LIMIT);

    refs.count.textContent = query.trim() === ''
      ? `${all.length} products`
      : `${found.length} ${found.length === 1 ? 'product matches' : 'products match'}`;
    if (found.length > shown.length) refs.count.textContent += `. Showing the first ${GRID_LIMIT}; type more to narrow it down.`;

    if (shown.length === 0) {
      append(clear(refs.grid), [h('li', { class: 'pos-grid__empty pos-muted' }, all.length === 0 ? 'This terminal has no products to sell.' : 'No product matches. Check the spelling, or scan the barcode.')]);
      return;
    }

    append(clear(refs.grid), shown.map((product) => h('li', {}, tile(product))));
  }

  function tile(product) {
    const status = stockStatus(product);
    const picture = h('span', { class: 'pos-tile__media' });
    if (product.media_id) {
      const image = h('img', { src: `${ctx.config.mediaBase}/${encodeURIComponent(product.media_id)}?thumb=1`, alt: '', loading: 'lazy', decoding: 'async' });
      image.addEventListener('error', () => picture.replaceChildren(productPlaceholder()));
      picture.append(image);
    } else {
      picture.append(productPlaceholder());
    }

    const price = formatPeso(parseMoney(product.price) ?? 0);
    return h('button', {
      class: 'pos-tile', type: 'button', 'data-variant': product.variant_id,
      'aria-label': `Add ${product.name}, ${price}${status === 'out' ? ', none in stock on record' : status === 'low' ? ', low stock' : ''}`,
      onClick: (event) => { addToTicket(product); afterClick(event); },
    },
    picture,
    h('span', { class: 'pos-tile__name' }, product.name),
    h('span', { class: 'pos-tile__foot' },
      h('span', { class: 'pos-tile__price' }, price),
      status === 'ok' ? null : h('span', { class: `pos-pill ${status === 'out' ? 'pos-pill--danger' : 'pos-pill--warn'}` }, status === 'out' ? 'Out of stock' : 'Low stock')));
  }

  // ---------------------------------------------------------------- scanning and searching

  function scan(text) {
    const code = normalizeScan(text);
    if (code === '') return;

    const hit = findByBarcode(ctx.snapshot.products(), code);
    if (hit !== null) {
      refs.search.value = '';
      query = '';
      drawGrid();
      addToTicket(hit);
      flash(`Added ${hit.name}.`, 'ok');
      return;
    }

    if (/^\d{4,}$/.test(code)) {
      // Digits and Enter: a scanner. Clear the box so the next scan does not join onto this one.
      refs.search.value = '';
      query = '';
      drawGrid();
      flash(`No product has the barcode ${code}. Check the number, or search by name.`, 'error');
      return;
    }

    flash(`No barcode matches \u201C${code}\u201D. The products that match the words are shown.`, 'info');
  }

  // ---------------------------------------------------------------- the ticket

  function addToTicket(product) {
    const result = addProduct(ctx.state.ticket, product);
    if (result.error !== null) {
      flash(`${product.name}: ${result.error}`, 'error');
      return;
    }
    ctx.state.ticket = result.ticket;
    refreshTicket();
    ctx.announce(`Added ${product.name}.`);
  }

  function lineEdit(product, apply, focusKey) {
    const result = apply(ctx.state.ticket);
    if (result.error !== null) {
      showLineMessage(`${product.name}: ${result.error}`);
      return;
    }
    refs.lineMessage.hidden = true;
    ctx.state.ticket = result.ticket;
    refreshTicket(focusKey);
  }

  function showLineMessage(text) {
    refs.lineMessage.textContent = text;
    refs.lineMessage.hidden = false;
  }

  function drawLine(line, product, pricedLine) {
    const rules = quantityRules(product);
    const quantityId = uid('qty');
    const note = oversellNote(product, line.quantityMilli);
    const atMinimum = line.quantityMilli - rules.unitMilli < rules.minMilli;

    const input = h('input', {
      id: quantityId, class: 'pos-input pos-qty__input', type: 'text', inputmode: rules.decimals ? 'decimal' : 'numeric', autocomplete: 'off',
      value: formatQuantity(line.quantityMilli), 'data-focus': `qty:${line.variantId}`, 'aria-label': `Quantity of ${product.name}`,
    });
    const commit = () => {
      const entered = parseQuantity(input.value);
      if (entered === null) {
        showLineMessage(`${product.name}: Enter a valid quantity (up to 3 decimals).`);
        input.value = formatQuantity(line.quantityMilli);
        return;
      }
      if (entered === line.quantityMilli) return;
      lineEdit(product, (ticket) => setQuantity(ticket, product, entered), `qty:${line.variantId}`);
      // A refused quantity leaves the line as it was; show that, not the refused text.
      if (document.body.contains(input)) input.value = formatQuantity(ctx.state.ticket.lines.find((l) => l.variantId === line.variantId)?.quantityMilli ?? line.quantityMilli);
    };
    input.addEventListener('change', commit);
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') { event.preventDefault(); commit(); focusSearch(); }
    });

    const step = (direction, name) => h('button', {
      class: 'pos-button pos-button--icon', type: 'button', 'data-focus': `${name}:${line.variantId}`,
      'aria-label': `${direction > 0 ? 'One more' : 'One less'} ${product.name}`,
      disabled: direction < 0 && atMinimum,
      onClick: (event) => { lineEdit(product, (ticket) => adjustQuantity(ticket, product, direction), `${name}:${line.variantId}`); afterClick(event); },
    }, icon(direction > 0 ? 'plus' : 'minus'));

    return h('li', { class: 'pos-line' },
      h('div', { class: 'pos-line__main' },
        h('strong', { class: 'pos-line__name' }, product.name),
        h('span', { class: 'pos-line__price pos-muted' }, `${formatPeso(pricedLine.unitPrice)} each`, pricedLine.tierLabel ? ` (${pricedLine.tierLabel})` : '')),
      h('div', { class: 'pos-line__controls' },
        h('div', { class: 'pos-qty', role: 'group', 'aria-label': `Quantity of ${product.name}` }, step(-1, 'minus'), input, step(1, 'plus')),
        h('strong', { class: 'pos-line__total' }, formatPeso(pricedLine.lineTotal)),
        h('button', {
          class: 'pos-button pos-button--icon pos-button--quiet', type: 'button', 'aria-label': `Remove ${product.name}`,
          onClick: (event) => { ctx.state.ticket = removeLine(ctx.state.ticket, line.variantId); refs.lineMessage.hidden = true; refreshTicket(); ctx.announce(`Removed ${product.name}.`); afterClick(event); },
        }, icon('trash'))),
      note === null ? null : h('p', { class: 'pos-line__note', role: 'note' }, icon('alert'), ' ', note));
  }

  function refreshTicket(focusKey = null) {
    if (!built) return;
    const { ticket, discount } = ctx.state;
    const productsByVariant = ctx.snapshot.productsByVariant();
    const entries = pricingEntries(ticket, productsByVariant);

    priced = null;
    let problem = null;
    try {
      priced = priceSale(entries, discount.rule);
    } catch (error) {
      problem = error instanceof RangeError ? error.message : 'The total could not be worked out.';
    }

    // Keep the keyboard where it was: find the control again after the lines are drawn.
    const keep = focusKey ?? document.activeElement?.dataset?.focus ?? null;

    const drawn = priced === null ? [] : priced.lines.map((pricedLine) => {
      const line = ticket.lines.find((candidate) => candidate.variantId === pricedLine.variantId);
      return drawLine(line, productsByVariant.get(pricedLine.variantId), pricedLine);
    });
    append(clear(refs.lines), drawn);
    refs.empty.hidden = ticket.lines.length > 0;

    drawTotals(problem);

    const itemCount = ticket.lines.length;
    refs.handle.replaceChildren(
      icon('ticket'),
      h('span', { class: 'pos-ticket__handle-text' }, `Ticket \u00B7 ${itemCount} ${itemCount === 1 ? 'item' : 'items'}`),
      h('strong', {}, formatPeso(priced?.total ?? 0)),
      icon(refs.ticket.classList.contains('is-open') ? 'chevronDown' : 'chevronUp'));

    refs.pay.disabled = priced === null || itemCount === 0;
    refs.clearButton.disabled = itemCount === 0;
    refs.discountButton.textContent = discount.rule === null ? 'Discount' : `Discount: ${discount.rule.label}`;

    if (keep !== null) refs.lines.querySelector(`[data-focus="${CSS.escape(keep)}"]`)?.focus();
  }

  function drawTotals(problem) {
    const { discount } = ctx.state;
    const vatRate = ctx.snapshot.get()?.vat_rate_percent ?? 12;
    if (problem !== null) {
      append(clear(refs.totals), [h('div', { class: 'pos-notice pos-notice--error' }, problem)]);
      return;
    }
    const money = (value) => formatPeso(value ?? 0);
    append(clear(refs.totals), [
      h('div', { class: 'pos-totals__row' }, h('dt', {}, 'Subtotal'), h('dd', {}, money(priced?.subtotal))),
      discount.rule === null ? null : h('div', { class: 'pos-totals__row' }, h('dt', {}, `Discount: ${discount.rule.label}`), h('dd', {}, `-${money(priced?.discountTotal)}`)),
      h('div', { class: 'pos-totals__row pos-totals__row--small' }, h('dt', {}, `VAT included (${vatRate}%)`), h('dd', {}, money(priced?.vatAmount))),
      h('div', { class: 'pos-totals__row pos-totals__row--grand' }, h('dt', {}, 'Total'), h('dd', {}, money(priced?.total))),
    ]);
  }

  async function clearTicket() {
    if (ctx.state.ticket.lines.length === 0) return;
    const yes = await confirmDialog({ title: 'Clear the ticket?', message: 'Every line will be taken off. This cannot be undone.', confirmLabel: 'Clear ticket', danger: true });
    if (yes) {
      ctx.state.ticket = emptyTicket();
      ctx.state.discount = { rule: null, idLast4: '' };
      refreshTicket();
      ctx.announce('Ticket cleared.');
    }
    focusSearch();
  }

  async function openPay() {
    if (priced === null || ctx.state.ticket.lines.length === 0) return;
    const done = await ctx.pay(priced);
    if (done) {
      refs.ticket.classList.remove('is-open');
      refs.handle.setAttribute('aria-expanded', 'false');
    }
    refreshTicket();
    focusSearch();
  }

  async function refreshProducts() {
    try {
      await ctx.snapshot.load(ctx.state.terminal.id);
      drawGrid();
      refreshTicket();
      flash('Prices and stock are up to date.', 'ok');
    } catch (error) {
      flash(`Could not refresh: ${ctx.errorMessage(error)}`, 'error');
    }
  }

  // ---------------------------------------------------------------- lifecycle

  function show() {
    if (!built) build();
    drawGrid();
    refreshTicket();
    document.addEventListener('keydown', onKeydown);
    focusSearch();
  }

  function hide() {
    document.removeEventListener('keydown', onKeydown);
  }

  return { show, hide, refreshTicket, focusSearch };
}
