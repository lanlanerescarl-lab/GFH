import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  initAuditDrawer,
  nextTrappedFocusIndex,
} from '../../templates/superadmin/assets/js/audit-drawer.js';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

function makeButton(attrs = {}) {
  const node = {
    attrs: { ...attrs },
    hidden: false,
    focusedCount: 0,
    getAttribute(name) {
      return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
    },
    closest(selector) {
      if (selector === '[data-sa-audit-inspect]' && this.getAttribute('data-sa-audit-inspect') !== null) {
        return this;
      }
      if (selector === '[data-sa-drawer-close]' && this.getAttribute('data-sa-drawer-close') !== null) {
        return this;
      }
      return null;
    },
    focus() {
      this.focusedCount += 1;
      if (this._root) {
        this._root.activeElement = this;
      }
    },
  };
  return node;
}

function makeFixture() {
  const listeners = new Map();
  const inspectBtn = makeButton({ 'data-sa-audit-inspect': 'evt-1' });
  const closeHeader = makeButton({ 'data-sa-drawer-close': '' });
  const closeFooter = makeButton({ 'data-sa-drawer-close': '' });

  const body = {
    children: [],
    replaceChildren(...nodes) {
      this.children = nodes;
    },
  };

  const dialog = {
    focusedCount: 0,
    focus() {
      this.focusedCount += 1;
      fakeDoc.activeElement = this;
    },
    querySelectorAll() {
      return [closeHeader, closeFooter];
    },
  };

  const backdrop = {
    hidden: true,
    contains(el) {
      return el === closeHeader || el === closeFooter || el === dialog || el === body;
    },
    querySelector(selector) {
      if (selector === '[role="dialog"]') return dialog;
      if (selector === '[data-sa-drawer-body]') return body;
      return null;
    },
  };

  const template = {
    content: {
      cloneNode(deep) {
        return { cloned: true, deep, text: 'safe-detail-fragment' };
      },
    },
  };

  const fakeDoc = {
    activeElement: null,
    querySelector(selector) {
      if (selector === '[data-sa-audit-drawer]') return backdrop;
      return null;
    },
    getElementById(id) {
      if (id === 'sa-audit-tpl-evt-1') return template;
      return null;
    },
    addEventListener(type, fn) {
      listeners.set(type, fn);
    },
    dispatch(type, event) {
      const fn = listeners.get(type);
      if (fn) fn(event);
    },
  };

  inspectBtn._root = fakeDoc;
  closeHeader._root = fakeDoc;
  closeFooter._root = fakeDoc;

  return { fakeDoc, backdrop, dialog, body, inspectBtn, closeHeader, closeFooter };
}

test('nextTrappedFocusIndex wraps forward and backward cleanly', () => {
  assert.equal(nextTrappedFocusIndex(0, 3, false), null);
  assert.equal(nextTrappedFocusIndex(2, 3, false), 0);
  assert.equal(nextTrappedFocusIndex(-1, 3, false), 0);
  assert.equal(nextTrappedFocusIndex(0, 3, true), 2);
  assert.equal(nextTrappedFocusIndex(2, 3, true), null);
  assert.equal(nextTrappedFocusIndex(0, 1, false), 0);
  assert.equal(nextTrappedFocusIndex(0, 0, false), null);
});

test('audit drawer opens via template clone, moves focus in, traps Tab, closes on Escape and returns focus', () => {
  const { fakeDoc, backdrop, body, inspectBtn, closeHeader, closeFooter } = makeFixture();
  const ctrl = initAuditDrawer(fakeDoc);
  assert.ok(ctrl);
  assert.equal(ctrl.isOpen(), false);

  // Clicking Inspect opens drawer, populates via cloneNode(true), and focuses first focusable inside.
  let prevented = false;
  fakeDoc.dispatch('click', {
    target: inspectBtn,
    preventDefault() { prevented = true; },
  });
  assert.equal(prevented, true);
  assert.equal(ctrl.isOpen(), true);
  assert.equal(backdrop.hidden, false);
  assert.deepEqual(body.children, [{ cloned: true, deep: true, text: 'safe-detail-fragment' }]);
  assert.equal(fakeDoc.activeElement, closeHeader);

  // Shift+Tab on first focusable wraps to last focusable inside drawer.
  let shiftWrapped = false;
  fakeDoc.dispatch('keydown', {
    key: 'Tab',
    shiftKey: true,
    preventDefault() { shiftWrapped = true; },
  });
  assert.equal(shiftWrapped, true);
  assert.equal(fakeDoc.activeElement, closeFooter);

  // Tab on last focusable wraps back to first focusable inside drawer.
  let tabWrapped = false;
  fakeDoc.dispatch('keydown', {
    key: 'Tab',
    shiftKey: false,
    preventDefault() { tabWrapped = true; },
  });
  assert.equal(tabWrapped, true);
  assert.equal(fakeDoc.activeElement, closeHeader);

  // Escape closes the drawer and returns focus to the triggering Inspect button.
  let escPrevented = false;
  fakeDoc.dispatch('keydown', {
    key: 'Escape',
    preventDefault() { escPrevented = true; },
  });
  assert.equal(escPrevented, true);
  assert.equal(ctrl.isOpen(), false);
  assert.equal(backdrop.hidden, true);
  assert.equal(fakeDoc.activeElement, inspectBtn);
});

test('audit drawer closes on Close button click and backdrop click with focus return', () => {
  const { fakeDoc, backdrop, inspectBtn, closeFooter } = makeFixture();
  const ctrl = initAuditDrawer(fakeDoc);

  ctrl.openFor('evt-1', inspectBtn);
  assert.equal(ctrl.isOpen(), true);

  let prevented = false;
  fakeDoc.dispatch('click', {
    target: closeFooter,
    preventDefault() { prevented = true; },
  });
  assert.equal(prevented, true);
  assert.equal(ctrl.isOpen(), false);
  assert.equal(fakeDoc.activeElement, inspectBtn);

  ctrl.openFor('evt-1', inspectBtn);
  fakeDoc.dispatch('click', {
    target: backdrop,
    preventDefault() {},
  });
  assert.equal(ctrl.isOpen(), false);
  assert.equal(fakeDoc.activeElement, inspectBtn);
});

test('audit-drawer.js contains no innerHTML or outerHTML sinks', () => {
  const source = readFileSync(resolve(root, 'templates/superadmin/assets/js/audit-drawer.js'), 'utf8');
  assert.equal(source.includes('innerHTML'), false);
  assert.equal(source.includes('outerHTML'), false);
  assert.equal(source.includes('insertAdjacentHTML'), false);
});
