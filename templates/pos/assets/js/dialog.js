// Modal dialogs on the native <dialog> element: focus stays inside, Escape closes, and the page behind is inert.
// Content is built with the dom helpers; nothing here puts text into HTML.

import { h, uid, append } from './dom.js';

/**
 * Opens a modal and returns {dialog, body, close}. `close(result)` closes it and resolves `closed`.
 *
 * @param {object} options
 * @param {string} options.title the heading, also the dialog's accessible name
 * @param {string} [options.className] extra class, for example 'pos-dialog--wide'
 * @param {boolean} [options.dismissible] whether Escape closes it (default true)
 */
export function openModal({ title, className = '', dismissible = true }) {
  const host = document.getElementById('pos-dialogs');
  const titleId = uid('dialog-title');
  const body = h('div', { class: 'pos-dialog__body' });
  const dialog = h('dialog', { class: `pos-dialog ${className}`.trim(), 'aria-labelledby': titleId }, h('h2', { class: 'pos-dialog__title', id: titleId }, title), body);

  let resolve;
  const closed = new Promise((done) => { resolve = done; });
  let result;

  dialog.addEventListener('close', () => {
    dialog.remove();
    resolve(result);
  });
  dialog.addEventListener('cancel', (event) => {
    if (!dismissible) event.preventDefault();
  });

  host.append(dialog);
  dialog.showModal();

  return {
    dialog,
    body,
    closed,
    close(value) {
      result = value;
      if (dialog.open) dialog.close();
      else dialog.dispatchEvent(new Event('close'));
    },
  };
}

/** A yes/no question. Resolves true when the person confirms. */
export function confirmDialog({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false }) {
  const modal = openModal({ title });
  const confirm = h('button', { class: `pos-button ${danger ? 'pos-button--danger' : 'pos-button--primary'}`, type: 'button', onClick: () => modal.close(true) }, confirmLabel);
  append(modal.body, [
    h('p', {}, message),
    h('div', { class: 'pos-actions' },
      h('button', { class: 'pos-button', type: 'button', onClick: () => modal.close(false) }, cancelLabel),
      confirm),
  ]);
  // The safe choice has the focus first.
  modal.body.querySelector('button')?.focus();
  return modal.closed.then((value) => value === true);
}
