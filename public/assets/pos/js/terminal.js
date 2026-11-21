// Screen: choose a terminal. Lists the active terminals with their branch and whether a shift is open.

import { h, $, clear, append } from './dom.js';

export function createTerminalScreen(ctx) {
  const root = $('#screen-terminal');
  let busy = false;

  async function show() {
    draw({ loading: true });
    try {
      ctx.state.terminals = await ctx.api.terminals();
    } catch (error) {
      draw({ error: ctx.errorMessage(error) });
      return;
    }
    draw({});
  }

  function draw({ loading = false, error = null }) {
    const heading = h('h1', { id: 'title-terminal', tabindex: '-1' }, 'Choose a terminal');
    const body = [heading];

    if (loading) {
      body.push(h('p', { class: 'pos-muted', role: 'status' }, 'Loading terminals\u2026'));
    } else if (error !== null) {
      body.push(
        h('div', { class: 'pos-notice pos-notice--error', role: 'alert' }, error),
        h('button', { class: 'pos-button pos-button--primary', type: 'button', onClick: show }, 'Try again'),
      );
    } else {
      const remembered = ctx.storage.lastTerminal();
      const terminals = [...ctx.state.terminals].sort((a, b) => Number(b.id === remembered) - Number(a.id === remembered));
      body.push(h('p', { class: 'pos-muted' }, 'Pick the till you are working at. Your choice is remembered on this device.'));

      if (terminals.length === 0) {
        body.push(h('div', { class: 'pos-notice' }, 'No terminals are switched on. An Admin can add one under Settings, Terminals.'));
      } else {
        body.push(h('ul', { class: 'pos-choices' }, ...terminals.map((terminal) => h('li', {},
          h('button', {
            class: 'pos-choice', type: 'button', disabled: busy, onClick: () => choose(terminal),
          },
          h('span', { class: 'pos-choice__code' }, terminal.code),
          h('span', { class: 'pos-choice__text' },
            h('strong', {}, terminal.name),
            h('span', { class: 'pos-muted' }, terminal.branch_name)),
          h('span', { class: 'pos-choice__tags' },
            terminal.id === remembered ? h('span', { class: 'pos-pill pos-pill--quiet' }, 'Last used') : null,
            h('span', { class: `pos-pill ${terminal.has_open_shift ? 'pos-pill--ok' : 'pos-pill--quiet'}` }, terminal.has_open_shift ? 'Shift open' : 'No shift')))))));
      }
    }

    append(clear(root), body);
  }

  async function choose(terminal) {
    busy = true;
    draw({ loading: true });
    try {
      await ctx.chooseTerminal(terminal);
    } catch (error) {
      busy = false;
      draw({ error: ctx.errorMessage(error) });
      return;
    }
    busy = false;
  }

  return { show, hide() {} };
}
