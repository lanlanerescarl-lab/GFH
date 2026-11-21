// Product gallery. Without this module every photo is visible, stacked, and the thumbnails are links that jump to
// them. With it, one photo shows at a time; the thumbnails stay real links, and the arrow keys, Home and End move
// between them.

export function initGallery(root) {
  const slides = [...root.querySelectorAll('[data-gallery-slide]')];
  const thumbs = [...root.querySelectorAll('[data-gallery-thumb]')];
  if (slides.length < 2 || thumbs.length !== slides.length) {
    return;
  }

  const status = root.querySelector('[data-gallery-status]');

  const show = (index, { focus = false, announce = true } = {}) => {
    slides.forEach((slide, i) => slide.classList.toggle('is-active', i === index));
    thumbs.forEach((thumb, i) => {
      if (i === index) {
        thumb.setAttribute('aria-current', 'true');
      } else {
        thumb.removeAttribute('aria-current');
      }
    });
    if (status && announce) {
      status.textContent = `Photo ${index + 1} of ${slides.length}`;
    }
    if (focus) {
      thumbs[index].focus();
    }
  };

  root.classList.add('is-enhanced');

  thumbs.forEach((thumb, index) => {
    thumb.addEventListener('click', (event) => {
      event.preventDefault();
      show(index);
    });

    thumb.addEventListener('keydown', (event) => {
      const last = thumbs.length - 1;
      const targets = {
        ArrowRight: index + 1,
        ArrowDown: index + 1,
        ArrowLeft: index - 1,
        ArrowUp: index - 1,
        Home: 0,
        End: last,
      };
      if (!(event.key in targets)) {
        return;
      }
      event.preventDefault();
      show((targets[event.key] + thumbs.length) % thumbs.length, { focus: true });
    });
  });

  // A link such as #photo-3 opens that photo.
  const fromHash = slides.findIndex((slide) => `#${slide.id}` === window.location.hash);
  show(fromHash >= 0 ? fromHash : 0, { announce: false });
}
