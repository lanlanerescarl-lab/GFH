// Page shell script, loaded as an ES module by the layouts. Without JavaScript the navigation stays visible.
document.documentElement.classList.add('js');

const button = document.querySelector('[data-nav-toggle]');
const nav = document.getElementById('shell-nav');

if (button && nav) {
  const setOpen = (open) => {
    nav.classList.toggle('is-open', open);
    button.setAttribute('aria-expanded', String(open));
  };

  button.addEventListener('click', () => setOpen(!nav.classList.contains('is-open')));

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && nav.classList.contains('is-open')) {
      setOpen(false);
      button.focus();
    }
  });
}
