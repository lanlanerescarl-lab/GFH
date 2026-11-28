// Super Admin sidebar drawer toggle (ES module, no globals, no raw markup sinks).
// Without JavaScript the sidebar stacks above the page and remains usable.
document.documentElement.classList.add('js');

const toggle = document.querySelector('[data-sa-nav-toggle]');
const sidebar = document.getElementById('sa-sidebar');
const closeButton = document.querySelector('[data-sa-nav-close]');
const backdrop = document.querySelector('[data-sa-backdrop]');

if (toggle && sidebar) {
  const isOpen = () => sidebar.classList.contains('is-open');

  const setOpen = (open, returnFocus = false) => {
    sidebar.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    if (backdrop) {
      backdrop.hidden = !open;
    }

    if (open) {
      const firstTarget = sidebar.querySelector('a, button');
      firstTarget?.focus();
    } else if (returnFocus) {
      toggle.focus();
    }
  };

  toggle.addEventListener('click', () => setOpen(!isOpen()));

  closeButton?.addEventListener('click', () => setOpen(false, true));
  backdrop?.addEventListener('click', () => setOpen(false, true));

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isOpen()) {
      setOpen(false, true);
    }
  });

  const desktopQuery = window.matchMedia?.('(min-width: 1024px)');
  desktopQuery?.addEventListener?.('change', (event) => {
    if (event.matches && isOpen()) {
      setOpen(false);
    }
  });
}
