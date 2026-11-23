// The receipt's Print button. It only opens the browser's print dialog; the page's print stylesheet does the rest.
export function initPrintButton(button) {
  button.hidden = false;
  button.addEventListener('click', () => window.print());
}
