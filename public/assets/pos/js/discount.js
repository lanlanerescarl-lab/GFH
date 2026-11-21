// Dialog: choose one active discount rule for the whole sale. A rule that needs an ID reference asks for the last
// four characters only; the full ID number is never typed here and never stored.

import { h, append, uid } from './dom.js';
import { openModal } from './dialog.js';
import { parsePercent } from './money.js';
import { discountNeedsId, checkIdLast4 } from './ticket.js';

function percentText(percent) {
  const hundredths = parsePercent(percent) ?? 0;
  return hundredths % 100 === 0 ? String(hundredths / 100) : (hundredths / 100).toFixed(2).replace(/0$/, '');
}

export function openDiscountDialog(ctx) {
  const rules = ctx.snapshot.get()?.discount_rules ?? [];
  const modal = openModal({ title: 'Discount' });
  const close = () => modal.close();

  if (rules.length === 0) {
    append(modal.body, [
      h('div', { class: 'pos-notice', role: 'status' },
        'No discount is switched on. An Admin can turn on the senior citizen and PWD rules under Settings, Discount rules.'),
      h('div', { class: 'pos-actions' }, h('button', { class: 'pos-button pos-button--primary', type: 'button', onClick: close }, 'Close')),
    ]);
    modal.body.querySelector('button').focus();
    return modal.closed;
  }

  let chosen = ctx.state.discount.rule;
  const idId = uid('id-last4');
  const hintId = uid('id-hint');
  const errorBox = h('p', { class: 'pos-field-error', role: 'alert', id: uid('id-error'), hidden: true });
  const idInput = h('input', {
    id: idId, class: 'pos-input', type: 'text', maxlength: '4', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false',
    'aria-describedby': `${hintId} ${errorBox.id}`, value: ctx.state.discount.idLast4,
  });
  const idGroup = h('div', { class: 'pos-field', hidden: !discountNeedsId(chosen) },
    h('label', { for: idId }, 'Last 4 characters of the ID'),
    idInput,
    h('p', { class: 'pos-hint', id: hintId }, 'Type only the last 4 letters or digits. The full ID number is never entered or stored.'),
    errorBox);

  const name = uid('discount');
  const radio = (rule) => {
    const input = h('input', { type: 'radio', name, value: rule?.key ?? '', checked: (chosen?.key ?? '') === (rule?.key ?? '') });
    input.addEventListener('change', () => {
      chosen = rule;
      idGroup.hidden = !discountNeedsId(rule);
      errorBox.hidden = true;
      if (!idGroup.hidden) idInput.focus();
    });
    return h('label', { class: 'pos-option' }, input,
      h('span', { class: 'pos-option__text' },
        h('strong', {}, rule === null ? 'No discount' : rule.label),
        rule === null ? null : h('span', { class: 'pos-muted' }, `${percentText(rule.percent)}% off${rule.vat_exempt ? ', VAT removed first' : ''}`)));
  };

  const form = h('form', { novalidate: true, onSubmit: (event) => {
    event.preventDefault();
    let idLast4 = '';
    if (discountNeedsId(chosen)) {
      const checked = checkIdLast4(idInput.value);
      if (!checked.ok) {
        errorBox.textContent = checked.message;
        errorBox.hidden = false;
        idInput.setAttribute('aria-invalid', 'true');
        idInput.focus();
        return;
      }
      idLast4 = checked.value;
    }
    ctx.state.discount = { rule: chosen, idLast4 };
    ctx.refreshTicket();
    ctx.announce(chosen === null ? 'Discount removed.' : `${chosen.label} discount applied.`);
    close();
  } },
  h('fieldset', { class: 'pos-fieldset' }, h('legend', {}, 'One discount applies to the whole sale'), radio(null), ...rules.map(radio)),
  idGroup,
  h('div', { class: 'pos-actions' },
    h('button', { class: 'pos-button', type: 'button', onClick: close }, 'Cancel'),
    h('button', { class: 'pos-button pos-button--primary', type: 'submit' }, 'Apply')));

  append(modal.body, [form]);
  modal.body.querySelector('input[type="radio"]:checked')?.focus();
  return modal.closed;
}
