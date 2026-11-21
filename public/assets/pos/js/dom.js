// A tiny DOM builder, so screens never put text into HTML strings. Every string becomes a text node,
// so a product name with markup in it can only ever be shown, never run.

/**
 * h('button', {class: 'pos-button', onClick: fn, disabled: true}, 'Pay')
 * Attributes: onXxx adds an event listener; class, id, type, aria-*, data-* are set as attributes;
 * hidden, disabled, checked, value, readOnly are set as properties; null, undefined and false are skipped.
 */
export function h(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [name, value] of Object.entries(attrs ?? {})) {
    if (value === null || value === undefined || value === false) continue;
    if (name.startsWith('on') && typeof value === 'function') {
      node.addEventListener(name.slice(2).toLowerCase(), value);
    } else if (['hidden', 'disabled', 'checked', 'value', 'readOnly', 'required'].includes(name)) {
      node[name] = value;
    } else {
      node.setAttribute(name, value === true ? '' : String(value));
    }
  }
  append(node, children);
  return node;
}

/** Adds children (one, or any nesting of arrays) to a node. Strings become text nodes; null, undefined and false are skipped. */
export function append(node, children) {
  for (const child of [children].flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return node;
}

export function clear(node) {
  node.replaceChildren();
  return node;
}

export function $(selector, root = document) {
  return root.querySelector(selector);
}

export function $$(selector, root = document) {
  return [...root.querySelectorAll(selector)];
}

let counter = 0;
/** A fresh id for linking a label or a message to a field. */
export function uid(prefix = 'pos') {
  counter += 1;
  return `${prefix}-${counter}`;
}
