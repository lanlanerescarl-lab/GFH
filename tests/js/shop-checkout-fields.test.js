// Node tests for the extension checkout fields (Batch 8.3): the definitions the page accepts, the request bodies, the
// error mapping and the plain tree each field type is built from. The DOM itself is covered by the hand-check.
import assert from 'node:assert/strict';
import test from 'node:test';

import { FIELD_TYPES, MAX_FIELDS, checkoutValues, errorId, extensionFields, fieldKey, fieldTree, helpId, inputId } from '../../templates/shop/assets/js/checkout-fields.js';
import { checkoutBody, pricingBody, splitErrors } from '../../templates/shop/assets/js/checkout-model.js';

const A = '11111111-1111-4111-8111-111111111111';
const lines = [{ variant_id: A, quantity: '2' }];
const pickup = { method: 'pickup', branch_id: 'b1' };

const all = () => [
  { id: 'gift_note', label: 'Gift note', type: 'textarea', required: false, help: 'We print it on a card.', max_length: 120 },
  { id: 'company', label: 'Company', type: 'text', required: true },
  { id: 'slot', label: 'Time slot', type: 'select', required: false, options: [{ value: 'am', label: 'Morning' }, { value: 'pm', label: 'Afternoon' }] },
  { id: 'gift_wrap', label: 'Gift wrap', type: 'checkbox', required: false },
];

const walk = (node, visit) => {
  visit(node);
  for (const child of node.children) {
    walk(child, visit);
  }
};

test('no extension fields: nothing is kept and the bodies are exactly what they were', () => {
  for (const nothing of [undefined, null, [], {}, 'x', 5]) {
    assert.deepEqual(extensionFields(nothing), []);
  }
  assert.deepEqual(checkoutBody(lines, { ...pickup }), { items: [{ variant_id: A, quantity: '2' }], fulfillment: { method: 'pickup', branch_id: 'b1' }, checkout: {} });
  assert.deepEqual(pricingBody(lines, { ...pickup }).checkout, {});
  assert.deepEqual(checkoutBody(lines, { ...pickup, checkout: {} }).checkout, {});
});

test('each field type is accepted with its own rules', () => {
  const fields = extensionFields(all());
  assert.deepEqual(fields.map((field) => field.type), ['textarea', 'text', 'select', 'checkbox']);
  assert.deepEqual([...FIELD_TYPES].sort(), ['checkbox', 'select', 'text', 'textarea']);
  const [note, company, slot, wrap] = fields;
  assert.equal(note.maxLength, 120);
  assert.equal(note.help, 'We print it on a card.');
  assert.equal(company.maxLength, 150, 'a text field defaults to 150');
  assert.equal(company.required, true);
  assert.deepEqual(slot.options, [{ value: 'am', label: 'Morning' }, { value: 'pm', label: 'Afternoon' }]);
  assert.equal(slot.maxLength, null);
  assert.equal(wrap.maxLength, null);
  assert.equal(extensionFields([{ id: 'n', label: 'N', type: 'textarea' }])[0].maxLength, 500, 'a textarea defaults to 500');
});

test('a malformed definition is dropped and the others stay', () => {
  const good = { id: 'ok', label: 'Fine', type: 'text' };
  const bad = [
    null, 'text', 7, [], {},
    { id: 'Bad Id', label: 'x', type: 'text' },
    { id: 'UPPER', label: 'x', type: 'text' },
    { id: 'a'.repeat(41), label: 'x', type: 'text' },
    { id: 'nolabel', label: '   ', type: 'text' },
    { id: 'notype', label: 'x', type: 'radio' },
    { id: 'emptysel', label: 'x', type: 'select', options: [] },
    { id: 'nooptions', label: 'x', type: 'select' },
    { id: 'badopt', label: 'x', type: 'select', options: [{ value: '', label: 'x' }] },
  ];
  const duplicate = { id: 'ok', label: 'duplicate id', type: 'text' };
  assert.deepEqual(extensionFields([...bad, good, duplicate]).map((field) => field.id), ['ok']);
  assert.equal(extensionFields([...bad, good, duplicate])[0].label, 'Fine', 'the first definition with an id wins');
});

test('at most ten fields are kept', () => {
  const many = Array.from({ length: 14 }, (_, index) => ({ id: `f${index}`, label: `Field ${index}`, type: 'text' }));
  assert.equal(MAX_FIELDS, 10);
  assert.equal(extensionFields(many).length, 10);
});

test('the checkout object holds trimmed text and true/false for declared fields and nothing else', () => {
  assert.deepEqual(checkoutValues(undefined), {});
  assert.deepEqual(checkoutValues({ company: '  Acme  ', gift_wrap: true, other: false, n: 5, z: null, o: { a: 1 } }), { company: 'Acme', gift_wrap: true, other: false });
  const body = checkoutBody(lines, { ...pickup, checkout: { company: ' Acme ', gift_wrap: true } });
  assert.deepEqual(body.checkout, { company: 'Acme', gift_wrap: true });
  assert.deepEqual(pricingBody(lines, { ...pickup, checkout: { company: 'Acme' } }).checkout, { company: 'Acme' });
});

test('a server error for an extension field is kept under its own key, other checkout errors stay general', () => {
  const split = splitErrors({ 'checkout.company': 'Company is required.', checkout: 'This field is not part of the checkout.', 'checkout.Bad Id': 'x', 'checkout.': 'y' });
  assert.deepEqual(split.fields, { 'checkout.company': 'Company is required.' });
  assert.equal(split.general.length, 3);
  assert.equal(fieldKey('company'), 'checkout.company');
});

test('every field type is described with its label tied to its input', () => {
  for (const field of extensionFields(all())) {
    const tree = fieldTree(field);
    const found = { labels: [], controls: [] };
    walk(tree, (node) => {
      if (node.tag === 'label') {
        found.labels.push(node);
      }
      if (['input', 'textarea', 'select'].includes(node.tag)) {
        found.controls.push(node);
      }
    });
    assert.equal(found.labels.length, 1, field.id);
    assert.equal(found.controls.length, 1, field.id);
    assert.equal(found.labels[0].props.for, found.controls[0].props.id, `${field.id}: label for matches the control id`);
    assert.equal(found.controls[0].props.id, inputId(field.id));
    assert.ok(found.controls[0].props['aria-describedby'].split(' ').includes(errorId(field.id)));
    assert.equal(found.controls[0].props['data-ext-field'], field.id);
    assert.equal(found.controls[0].props['aria-required'], field.required ? 'true' : null);
    assert.equal(found.labels[0].props.text, field.required ? `${field.label} (required)` : field.label);
  }
});

test('text, textarea, select and checkbox each render as the right control', () => {
  const [note, company, slot, wrap] = extensionFields(all());
  const control = (field) => {
    let node = null;
    walk(fieldTree(field), (n) => {
      if (['input', 'textarea', 'select'].includes(n.tag)) {
        node = n;
      }
    });
    return node;
  };
  assert.equal(control(company).tag, 'input');
  assert.equal(control(company).props.type, 'text');
  assert.equal(control(company).props.maxlength, 150);
  assert.equal(control(note).tag, 'textarea');
  assert.equal(control(note).props.maxlength, 120);
  assert.equal(control(slot).tag, 'select');
  assert.deepEqual(control(slot).children.map((option) => option.props.value), ['', 'am', 'pm'], 'an empty choice first, then the options');
  assert.deepEqual(control(slot).children.map((option) => option.props.text), ['Choose an option', 'Morning', 'Afternoon']);
  assert.equal(control(wrap).props.type, 'checkbox');
  // A checkbox sits in the same row layout as the radios of the page, with its label beside it.
  const row = fieldTree(wrap).children[0];
  assert.equal(row.props.class, 'choice');
  assert.deepEqual(row.children.map((node) => node.tag), ['input', 'label']);
});

test('help text and the error are tied to the field', () => {
  const [note] = extensionFields(all());
  const tree = fieldTree(note);
  const ids = [];
  walk(tree, (node) => {
    if (node.props.id) {
      ids.push(node.props.id);
    }
  });
  assert.deepEqual(ids.sort(), [errorId('gift_note'), helpId('gift_note'), inputId('gift_note')].sort());
  let control = null;
  walk(tree, (node) => {
    if (node.tag === 'textarea') {
      control = node;
    }
  });
  assert.equal(control.props['aria-describedby'], `${helpId('gift_note')} ${errorId('gift_note')}`);
  // No help: no help element, and the control points at the error only.
  const [, company] = extensionFields(all());
  const plain = fieldTree(company);
  assert.equal(plain.children.some((node) => node.props.class === 'help'), false);
});

test('ids stay unique across all the fields of a form, and clear of the address fields', () => {
  const ids = [];
  for (const field of extensionFields(all())) {
    walk(fieldTree(field), (node) => {
      if (node.props.id) {
        ids.push(node.props.id);
      }
    });
  }
  assert.equal(new Set(ids).size, ids.length);
  for (const id of ids) {
    assert.match(id, /^checkout-ext-/);
  }
});

test('text from a definition is only ever text, never markup', () => {
  const hostile = '<img src=x onerror=alert(1)> & "quotes"';
  const field = extensionFields([
    { id: 'x', label: hostile, type: 'select', help: hostile, options: [{ value: 'v', label: hostile }] },
  ])[0];
  assert.equal(field.label, hostile, 'the definition is kept as written; escaping happens when it is placed in the page as text');
  walk(fieldTree(field), (node) => {
    for (const name of Object.keys(node.props)) {
      assert.doesNotMatch(name, /^(on|inner|outer|html)/i, `no ${name} prop`);
    }
    for (const [name, value] of Object.entries(node.props)) {
      if (typeof value === 'string' && value.includes('<img') && name !== 'text') {
        assert.fail(`markup text reached the "${name}" prop`);
      }
    }
  });
});
