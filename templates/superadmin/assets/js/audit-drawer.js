// Super Admin Audit Event Inspect drawer (ES module, no globals, no raw HTML sinks).
// Moves focus into the drawer on open, traps Tab/Shift+Tab inside, closes on Escape/Close/backdrop,
// and returns focus to the triggering Inspect control.

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ');

/**
 * Pure helper for circular Tab focus trapping inside a dialog.
 * Returns the index of the next focusable element when wrapping is required, or null to let the browser proceed.
 */
export function nextTrappedFocusIndex(currentIndex, count, shiftKey) {
  if (count <= 0) {
    return null;
  }
  if (count === 1) {
    return 0;
  }
  if (shiftKey) {
    return currentIndex <= 0 ? count - 1 : null;
  }
  return currentIndex === -1 || currentIndex >= count - 1 ? 0 : null;
}

/**
 * Wires an Audit Event drawer controller against any document/root implementing standard DOM methods.
 * Copies pre-escaped server-rendered <template> content via cloneNode(true) + replaceChildren()
 * so no raw HTML string sink is ever used.
 */
export function initAuditDrawer(root = document) {
  const backdrop = root.querySelector('[data-sa-audit-drawer]');
  if (!backdrop) {
    return null;
  }

  const dialog = backdrop.querySelector('[role="dialog"]') || backdrop;
  const body = backdrop.querySelector('[data-sa-drawer-body]');
  let opener = null;

  const isOpen = () => !backdrop.hidden;

  const focusableElements = () => Array.from(dialog.querySelectorAll(FOCUSABLE_SELECTOR))
    .filter((el) => !el.hidden && el.getAttribute('aria-hidden') !== 'true');

  const openFor = (eventId, trigger) => {
    const template = root.getElementById(`sa-audit-tpl-${eventId}`);
    if (!template || !body) {
      return false;
    }

    opener = trigger || null;
    body.replaceChildren(template.content.cloneNode(true));
    backdrop.hidden = false;

    const targets = focusableElements();
    if (targets.length > 0) {
      targets[0].focus();
    } else {
      dialog.focus();
    }
    return true;
  };

  const close = (returnFocus = true) => {
    if (!isOpen()) {
      return;
    }
    backdrop.hidden = true;
    const target = opener;
    opener = null;
    if (returnFocus && target && typeof target.focus === 'function') {
      target.focus();
    }
  };

  const onClick = (event) => {
    const inspectBtn = event.target?.closest?.('[data-sa-audit-inspect]');
    if (inspectBtn) {
      const eventId = inspectBtn.getAttribute('data-sa-audit-inspect');
      if (eventId && openFor(eventId, inspectBtn)) {
        event.preventDefault();
      }
      return;
    }

    const closeBtn = event.target?.closest?.('[data-sa-drawer-close]');
    if (closeBtn && backdrop.contains(closeBtn) && isOpen()) {
      event.preventDefault();
      close(true);
      return;
    }

    if (isOpen() && event.target === backdrop) {
      close(true);
    }
  };

  const onKeydown = (event) => {
    if (!isOpen()) {
      return;
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      close(true);
      return;
    }

    if (event.key === 'Tab') {
      const nodes = focusableElements();
      if (nodes.length === 0) {
        event.preventDefault();
        dialog.focus();
        return;
      }

      const active = root.activeElement;
      const currentIndex = nodes.indexOf(active);
      const nextIndex = nextTrappedFocusIndex(currentIndex, nodes.length, Boolean(event.shiftKey));
      if (nextIndex !== null) {
        event.preventDefault();
        nodes[nextIndex].focus();
      }
    }
  };

  root.addEventListener('click', onClick);
  root.addEventListener('keydown', onKeydown);

  // If the server opened the drawer via ?inspect=..., move focus into it on load.
  if (isOpen()) {
    const targets = focusableElements();
    (targets[0] || dialog).focus();
  }

  return { openFor, close, isOpen };
}

if (typeof document !== 'undefined') {
  initAuditDrawer(document);
}
