// Listing filters: sends the form when a choice changes, and turns the filter panel into a drawer on narrow
// screens. Without this module the form is an ordinary GET form with an Apply button, and the panel sits above the
// results. The address always carries the whole state.

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled])';

export function initFilterDrawer(form) {
  // Choices (radio buttons, checkboxes, selects) send the form at once. Text boxes do not: someone typing a price
  // range would be sent away after the first box. They use Enter or the Apply button.
  const sendOnChange = (event) => {
    const target = event.target;
    const isChoice = target instanceof HTMLSelectElement
      || (target instanceof HTMLInputElement && (target.type === 'radio' || target.type === 'checkbox'));
    if (isChoice) {
      form.requestSubmit();
    }
  };
  form.addEventListener('change', sendOnChange);
  document.querySelectorAll('select[data-autosubmit][form="shop-filter-form"]').forEach((select) => {
    select.addEventListener('change', () => form.requestSubmit());
  });

  const panel = document.querySelector('[data-filter-panel]');
  const openButton = document.querySelector('[data-filter-open]');
  const backdrop = document.querySelector('.shop-backdrop');
  const closeButton = panel?.querySelector('.shop-filters__close');
  if (!panel || !openButton || !backdrop || !closeButton) {
    return;
  }

  // CSS decides when the toggle and the close button are visible (narrow screens only).
  openButton.hidden = false;
  closeButton.hidden = false;

  const narrow = window.matchMedia('(max-width: 839px)');

  const setOpen = (open) => {
    panel.classList.toggle('is-open', open);
    backdrop.hidden = !open;
    openButton.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('has-drawer', open);

    if (open) {
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-modal', 'true');
      closeButton.focus();
    } else {
      panel.removeAttribute('role');
      panel.removeAttribute('aria-modal');
      // Always back to the Filters button (some browsers do not focus a button when it is clicked).
      openButton.focus();
    }
  };

  const isOpen = () => panel.classList.contains('is-open');

  openButton.addEventListener('click', () => setOpen(true));
  panel.querySelectorAll('[data-filter-close]').forEach((button) => button.addEventListener('click', () => setOpen(false)));
  backdrop.addEventListener('click', () => setOpen(false));

  document.addEventListener('keydown', (event) => {
    if (!isOpen()) {
      return;
    }
    if (event.key === 'Escape') {
      setOpen(false);
      return;
    }
    if (event.key === 'Tab') {
      // Keep the keyboard inside the open drawer.
      const items = [...panel.querySelectorAll(FOCUSABLE)].filter((item) => item.offsetParent !== null);
      if (items.length === 0) {
        return;
      }
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });

  // If the window grows past the drawer breakpoint while it is open, close it.
  narrow.addEventListener('change', () => {
    if (!narrow.matches && isOpen()) {
      setOpen(false);
    }
  });
}
