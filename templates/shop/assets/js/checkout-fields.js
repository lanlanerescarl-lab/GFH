// Extension checkout fields (Batch 8.3), with no DOM so Node can test them. The server decides what a field is and
// checks every posted value again; this file only keeps a malformed definition from breaking the page and describes
// each field as a plain tree that checkout.js turns into elements. Text is only ever placed in `text` props and text
// nodes, never in markup, so a label, a help text or an option cannot become HTML.

export const FIELD_TYPES = ['text', 'textarea', 'select', 'checkbox'];
export const MAX_FIELDS = 10;

const ID = /^[a-z0-9_]{1,40}$/;
const DEFAULT_MAX_LENGTH = { text: 150, textarea: 500 };

const isText = (value) => typeof value === 'string' && value.trim() !== '';

/** The key a field's server error has in the API's `fields` (`checkout.{id}`), and the keys the page uses for it. */
export const fieldKey = (id) => `checkout.${id}`;
export const inputId = (id) => `checkout-ext-${id}`;
export const helpId = (id) => `checkout-ext-${id}-help`;
export const errorId = (id) => `checkout-ext-${id}-error`;

/**
 * The usable definitions from `GET /checkout/options`. The server already dropped the bad ones; this repeats the check
 * cheaply so an odd answer (an old cache, a hand-made response) shows fewer fields rather than a broken page.
 *
 * @returns {{id: string, label: string, type: string, required: boolean, options: {value: string, label: string}[], help: string, maxLength: number|null}[]}
 */
export function extensionFields(raw) {
  const fields = [];
  const seen = new Set();
  for (const item of Array.isArray(raw) ? raw : []) {
    if (fields.length >= MAX_FIELDS || item === null || typeof item !== 'object') {
      continue;
    }
    if (typeof item.id !== 'string' || !ID.test(item.id) || seen.has(item.id) || !isText(item.label) || !FIELD_TYPES.includes(item.type)) {
      continue;
    }
    let options = [];
    if (item.type === 'select') {
      options = (Array.isArray(item.options) ? item.options : []).filter((option) => option !== null && typeof option === 'object' && isText(option.value) && isText(option.label));
      if (options.length === 0) {
        continue;
      }
      options = options.map((option) => ({ value: option.value, label: option.label }));
    }
    let maxLength = null;
    if (item.type === 'text' || item.type === 'textarea') {
      maxLength = Number.isInteger(item.max_length) && item.max_length > 0 ? item.max_length : DEFAULT_MAX_LENGTH[item.type];
    }
    seen.add(item.id);
    fields.push({ id: item.id, label: item.label.trim(), type: item.type, required: item.required === true, options, help: typeof item.help === 'string' ? item.help.trim() : '', maxLength });
  }
  return fields;
}

/**
 * The `checkout` object of a request: what the customer entered for each declared field, as text or true/false, and
 * nothing else. With no declared fields it is {} (the body the page sent before extension fields existed).
 *
 * @param {Record<string, unknown>|undefined} entered field id => value read from the form
 */
export function checkoutValues(entered) {
  const values = {};
  for (const [id, value] of Object.entries(entered ?? {})) {
    if (typeof value === 'boolean') {
      values[id] = value;
    } else if (typeof value === 'string') {
      values[id] = value.trim();
    }
  }
  return values;
}

/**
 * One field as a plain tree: {tag, props, children}, where a child is a tree or a string. `props.text` is text content.
 * The label is tied to the input with for/id, the help and the error with aria-describedby, as partials/field.php does.
 */
export function fieldTree(field) {
  const id = inputId(field.id);
  const describedBy = [field.help !== '' ? helpId(field.id) : '', errorId(field.id)].filter(Boolean).join(' ');
  const labelText = field.required ? `${field.label} (required)` : field.label;
  const common = { id, name: `ext_${field.id}`, 'aria-required': field.required ? 'true' : null, 'aria-describedby': describedBy, 'data-ext-field': field.id };
  const help = field.help !== '' ? { tag: 'p', props: { class: 'help', id: helpId(field.id), text: field.help }, children: [] } : null;
  const error = { tag: 'p', props: { class: 'field-error', id: errorId(field.id) }, children: [] };
  const label = { tag: 'label', props: { for: id, text: labelText }, children: [] };

  let control;
  if (field.type === 'textarea') {
    control = { tag: 'textarea', props: { ...common, rows: 4, maxlength: field.maxLength }, children: [] };
  } else if (field.type === 'select') {
    const empty = { tag: 'option', props: { value: '', text: 'Choose an option' }, children: [] };
    control = { tag: 'select', props: common, children: [empty, ...field.options.map((option) => ({ tag: 'option', props: { value: option.value, text: option.label }, children: [] }))] };
  } else if (field.type === 'checkbox') {
    control = { tag: 'input', props: { ...common, type: 'checkbox' }, children: [] };
    return { tag: 'div', props: { class: 'field' }, children: [{ tag: 'div', props: { class: 'choice' }, children: [control, label] }, help, error].filter(Boolean) };
  } else {
    control = { tag: 'input', props: { ...common, type: 'text', maxlength: field.maxLength }, children: [] };
  }
  return { tag: 'div', props: { class: 'field' }, children: [label, control, help, error].filter(Boolean) };
}
