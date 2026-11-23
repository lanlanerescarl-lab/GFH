// Small helpers that build page content safely. Text from the server is always written with textContent (a text node),
// never innerHTML, so nothing a product, a customer or a message contains can become markup.

const BLOCKED = /^(on|inner|outer)/i;

/**
 * Builds an element. props: "class", "text" (textContent), anything else becomes an attribute; true sets a
 * boolean attribute and false/null/undefined leaves it off. Event handler attributes are refused.
 */
export function el(tag, props = {}, ...children) {
  const node = document.createElement(tag);
  for (const [name, value] of Object.entries(props)) {
    if (value === false || value === null || value === undefined || BLOCKED.test(name)) {
      continue;
    }
    if (name === 'text') {
      node.textContent = String(value);
    } else if (name === 'class') {
      node.className = String(value);
    } else {
      node.setAttribute(name, value === true ? '' : String(value));
    }
  }
  for (const child of children.flat()) {
    if (child === null || child === undefined || child === false) {
      continue;
    }
    node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return node;
}

/** Removes everything inside a node. */
export function clear(node) {
  node.replaceChildren();
  return node;
}

/**
 * Says something to a screen reader through a polite live region. Clearing first makes a repeated message
 * (the same text twice) announce again.
 */
export function announce(region, message) {
  if (!region) {
    return;
  }
  region.textContent = '';
  window.setTimeout(() => {
    region.textContent = message;
  }, 50);
}

/** The shop's settings, written on <body> by the layout: the organization slug, the API root and the CSRF token. */
export function readConfig(doc = document) {
  const data = doc.body.dataset;
  return { slug: data.shopSlug ?? '', apiBase: data.shopApi ?? '', csrf: data.shopCsrf ?? '' };
}
