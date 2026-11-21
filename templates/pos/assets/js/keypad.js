// An on-screen number keypad for cash amounts, with a real input as its display so a keyboard works too.

import { h } from './dom.js';
import { keypadPress } from './money.js';

const KEYS = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0', 'back'];

/**
 * @param {object} options
 * @param {string} options.label the field's accessible name
 * @param {string} [options.id] the input's id
 * @param {(text: string) => void} [options.onChange] called after every change
 * @returns {{element: HTMLElement, input: HTMLInputElement, value: () => string}}
 */
export function createKeypad({ label, id, onChange = () => {} }) {
  let last = '';
  const input = h('input', {
    class: 'pos-amount', id, type: 'text', inputmode: 'decimal', autocomplete: 'off', 'aria-label': label, placeholder: '0.00', maxlength: '12',
  });

  const set = (text) => {
    last = text;
    input.value = text;
    onChange(text);
  };

  // Typing on a keyboard follows the same rules as the keys: digits and one point, two decimals at most.
  input.addEventListener('input', () => {
    if (/^\d{0,9}(\.\d{0,2})?$/.test(input.value)) set(input.value);
    else input.value = last;
  });

  const keys = KEYS.map((key) => h('button', {
    class: 'pos-key', type: 'button', 'aria-label': key === 'back' ? 'Delete the last digit' : key === '.' ? 'Decimal point' : key,
    onClick: () => { set(keypadPress(input.value, key)); input.focus(); },
  }, key === 'back' ? '\u232B' : key));

  const element = h('div', { class: 'pos-keypad' }, input, h('div', { class: 'pos-keypad__keys' }, ...keys));

  return { element, input, value: () => input.value };
}
