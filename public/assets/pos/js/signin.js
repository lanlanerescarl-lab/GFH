// Dialog: sign in again when the session ended. The ticket on screen is untouched; after signing in the call that
// was refused is sent again by api.js. Nothing typed here is stored anywhere.

import { h, append, uid } from './dom.js';
import { openModal } from './dialog.js';

/**
 * Opens the dialog. Resolves once api.login() has succeeded; rejects if the person cancels.
 * @param {{login: (email: string, password: string) => Promise<object>}} api
 */
export function openSignIn(api) {
  const modal = openModal({ title: 'Sign in again', dismissible: true });
  const emailId = uid('si-email');
  const passwordId = uid('si-password');
  const error = h('div', { class: 'pos-notice pos-notice--error', role: 'alert', hidden: true });
  const submit = h('button', { class: 'pos-button pos-button--primary', type: 'submit' }, 'Sign in');

  const form = h('form', { novalidate: true, onSubmit: async (event) => {
    event.preventDefault();
    const email = form.elements.email.value.trim();
    const password = form.elements.password.value;
    if (email === '' || password === '') {
      error.hidden = false;
      error.textContent = 'Enter your email and password.';
      return;
    }
    submit.disabled = true;
    error.hidden = true;
    try {
      await api.login(email, password);
      form.elements.password.value = '';
      modal.close('signed-in');
    } catch (failure) {
      submit.disabled = false;
      error.hidden = false;
      error.textContent = failure.message;
      form.elements.password.value = '';
      form.elements.password.focus();
    }
  } },
  h('div', { class: 'pos-field' }, h('label', { for: emailId }, 'Email'), h('input', { id: emailId, name: 'email', class: 'pos-input', type: 'email', autocomplete: 'username' })),
  h('div', { class: 'pos-field' }, h('label', { for: passwordId }, 'Password'), h('input', { id: passwordId, name: 'password', class: 'pos-input', type: 'password', autocomplete: 'current-password' })),
  error,
  h('div', { class: 'pos-actions' },
    h('button', { class: 'pos-button', type: 'button', onClick: () => modal.close('cancelled') }, 'Not now'),
    submit));

  append(modal.body, [
    h('p', {}, 'Your session ended. Sign in to carry on. Your ticket is kept exactly as it is.'),
    form,
  ]);
  form.elements.email.focus();

  return modal.closed.then((outcome) => {
    if (outcome !== 'signed-in') throw new Error('Sign-in was cancelled.');
  });
}
