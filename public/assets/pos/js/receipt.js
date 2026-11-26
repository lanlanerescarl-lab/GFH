// The receipt. The layout is the template element the server embeds (templates/pos/partials/receipt.php, which an
// organization can override); this file only writes text into its data-bind and data-list spots. All text goes in
// as text, so nothing from a product name or a reference can become markup.

import { h, $, $$ } from './dom.js';

/** A receipt element for these fields, cloned from the template element with the given id. */
export function renderReceipt(templateId, data) {
  const template = document.getElementById(templateId);
  const root = template.content.firstElementChild.cloneNode(true);

  for (const node of $$('[data-bind]', root)) {
    const name = node.dataset.bind;
    // A name the data does not carry keeps whatever the template says (the footer line, for example).
    if (Object.hasOwn(data, name) && typeof data[name] === 'string') node.textContent = data[name];
  }

  fillList($('[data-list="lines"]', root), data.lines, (line) => h('li', { class: 'receipt__line' },
    h('span', { class: 'receipt__name' }, line.name),
    h('span', { class: 'receipt__calc' }, line.calc),
    h('span', { class: 'receipt__amount' }, line.lineTotal),
    // Extension lines under their sale line (Batch 8.3b), as text like everything else on the receipt.
    ...(line.notes ?? []).map((note) => h('span', { class: 'receipt__note' }, note))));
  addFooterNotes(root, data.footerNotes);

  fillList($('[data-list="payments"]', root), data.payments, (payment) => h('li', { class: 'receipt__payment' },
    h('span', { class: 'receipt__name' }, payment.reference ? `${payment.label} (${payment.reference})` : payment.label),
    h('span', { class: 'receipt__amount' }, payment.amount)));

  // Rows that exist only when they have something to say.
  for (const node of $$('[data-show]', root)) {
    const value = data[node.dataset.show];
    node.hidden = !(typeof value === 'string' ? value !== '' : Boolean(value));
  }

  return root;
}

/**
 * Extension lines that print once, under the totals (Batch 8.3b). They go before the footer line, and at the end when a
 * template has none, so an organization's own receipt template needs no change to print them. Without any, nothing at all.
 */
function addFooterNotes(root, notes) {
  if (!Array.isArray(notes) || notes.length === 0) return;
  const list = h('ul', { class: 'receipt__notes' }, ...notes.map((note) => h('li', { class: 'receipt__note-item' }, note)));
  const footer = $('[data-bind="footer"]', root);
  if (footer !== null) footer.before(list);
  else root.append(list);
}

function fillList(list, items, build) {
  if (list === null) return;
  list.replaceChildren(...items.map(build));
}
