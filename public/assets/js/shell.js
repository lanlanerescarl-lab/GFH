// Page shell script, loaded as an ES module by the layouts. Without JavaScript the navigation stays visible.
document.documentElement.classList.add('js');

const button = document.querySelector('[data-nav-toggle]');
const nav = document.getElementById('shell-nav');

if (button && nav) {
  const isOpen = () => nav.classList.contains('is-open');

  const setOpen = (open, returnFocus = false) => {
    nav.classList.toggle('is-open', open);
    button.setAttribute('aria-expanded', String(open));
    if (open) {
      // In the Admin app the menu opens as a side panel; put the keyboard where the links are.
      nav.querySelector('a')?.focus();
    } else if (returnFocus) {
      button.focus();
    }
  };

  button.addEventListener('click', () => setOpen(!isOpen()));

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isOpen()) {
      setOpen(false, true);
    }
  });

  document.addEventListener('click', (event) => {
    if (isOpen() && !nav.contains(event.target) && !button.contains(event.target)) {
      setOpen(false);
    }
  });
}
