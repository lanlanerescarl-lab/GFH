/**
 * Admin UI component behaviors (ES module).
 * Keyboard operation and state transitions for tabs, segmented controls, switches, tags, modals,
 * drawers, toasts, sortable/filterable/paginated data tables, and Chart.js charts.
 */
import { mountDeclarativeCharts } from './chart-theme.js';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function wireRovingButtons(container, buttonSelector, activate) {
  const buttons = () => Array.from(container.querySelectorAll(buttonSelector)).filter((b) => !b.disabled);

  container.addEventListener('click', (event) => {
    const button = event.target.closest(buttonSelector);
    if (button && container.contains(button) && !button.disabled) {
      activate(button, false);
    }
  });

  container.addEventListener('keydown', (event) => {
    const current = event.target.closest(buttonSelector);
    if (!current || !container.contains(current)) {
      return;
    }
    const list = buttons();
    const index = list.indexOf(current);
    if (index === -1) {
      return;
    }

    let nextIndex = -1;
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
      nextIndex = (index + 1) % list.length;
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
      nextIndex = (index - 1 + list.length) % list.length;
    } else if (event.key === 'Home') {
      nextIndex = 0;
    } else if (event.key === 'End') {
      nextIndex = list.length - 1;
    }

    if (nextIndex !== -1) {
      event.preventDefault();
      activate(list[nextIndex], true);
    }
  });
}

function initTabs(root = document) {
  for (const tabs of root.querySelectorAll('[data-tabs]')) {
    const tabList = tabs.querySelector('[role="tablist"]');
    if (!tabList) {
      continue;
    }
    wireRovingButtons(tabList, '[role="tab"]', (selected, moveFocus) => {
      for (const tab of tabList.querySelectorAll('[role="tab"]')) {
        const isCurrent = tab === selected;
        tab.setAttribute('aria-selected', String(isCurrent));
        tab.setAttribute('tabindex', isCurrent ? '0' : '-1');
        const panelId = tab.getAttribute('aria-controls');
        const panel = panelId ? document.getElementById(panelId) : null;
        if (panel) {
          panel.hidden = !isCurrent;
        }
      }
      if (moveFocus) {
        selected.focus();
      }
    });
  }
}

function initSegmentedControls(root = document) {
  for (const group of root.querySelectorAll('[data-segmented]')) {
    wireRovingButtons(group, '.segmented__option', (selected, moveFocus) => {
      for (const option of group.querySelectorAll('.segmented__option')) {
        const isCurrent = option === selected;
        option.setAttribute('aria-pressed', String(isCurrent));
        option.setAttribute('tabindex', isCurrent ? '0' : '-1');
      }
      if (moveFocus) {
        selected.focus();
      }
    });
  }
}

function initSwitches(root = document) {
  root.addEventListener('click', (event) => {
    const toggle = event.target.closest('button[role="switch"]');
    if (!toggle || toggle.disabled) {
      return;
    }
    const next = toggle.getAttribute('aria-checked') !== 'true';
    toggle.setAttribute('aria-checked', String(next));
    const hiddenId = toggle.getAttribute('data-switch-input');
    const hidden = hiddenId ? document.getElementById(hiddenId) : null;
    if (hidden) {
      hidden.value = next ? '1' : '0';
    }
  });
}

function initTags(root = document) {
  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-tag-remove]');
    if (!button) {
      return;
    }
    const tag = button.closest('.tag');
    if (tag) {
      const nextFocus = tag.nextElementSibling?.querySelector('[data-tag-remove]')
        || tag.previousElementSibling?.querySelector('[data-tag-remove]');
      tag.remove();
      nextFocus?.focus();
    }
  });
}

function initOverlays(root = document) {
  let activeOverlay = null;
  let opener = null;

  const closeActive = () => {
    if (!activeOverlay) {
      return;
    }
    activeOverlay.hidden = true;
    const returnTo = opener;
    activeOverlay = null;
    opener = null;
    returnTo?.focus();
  };

  root.addEventListener('click', (event) => {
    const openTrigger = event.target.closest('[data-overlay-open]');
    if (openTrigger) {
      const targetId = openTrigger.getAttribute('data-overlay-open');
      const overlay = targetId ? document.getElementById(targetId) : null;
      if (overlay) {
        opener = openTrigger;
        activeOverlay = overlay;
        overlay.hidden = false;
        overlay.querySelector(FOCUSABLE)?.focus();
      }
      return;
    }

    const closeTrigger = event.target.closest('[data-overlay-close]');
    if (closeTrigger && activeOverlay && activeOverlay.contains(closeTrigger)) {
      closeActive();
      return;
    }

    if (activeOverlay && event.target === activeOverlay) {
      closeActive();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (!activeOverlay) {
      return;
    }
    if (event.key === 'Escape') {
      event.preventDefault();
      closeActive();
      return;
    }
    if (event.key === 'Tab') {
      const dialog = activeOverlay.querySelector('[role="dialog"]') || activeOverlay;
      const nodes = Array.from(dialog.querySelectorAll(FOCUSABLE));
      if (nodes.length === 0) {
        return;
      }
      const first = nodes[0];
      const last = nodes[nodes.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });
}

function initToasts(root = document) {
  root.addEventListener('click', (event) => {
    const dismiss = event.target.closest('[data-toast-close]');
    if (dismiss) {
      dismiss.closest('.toast')?.remove();
      return;
    }

    const trigger = event.target.closest('[data-toast-trigger]');
    if (!trigger) {
      return;
    }
    const region = document.getElementById('admin-toast-region');
    if (!region) {
      return;
    }
    const tone = trigger.getAttribute('data-toast-tone') || 'info';
    const title = trigger.getAttribute('data-toast-title') || 'Notice';
    const message = trigger.getAttribute('data-toast-message') || '';

    const toast = document.createElement('div');
    toast.className = `toast toast--${tone}`;
    toast.setAttribute('role', tone === 'danger' ? 'alert' : 'status');

    const body = document.createElement('div');
    const heading = document.createElement('p');
    heading.className = 'toast__title';
    heading.textContent = title;
    body.appendChild(heading);

    if (message) {
      const detail = document.createElement('p');
      detail.className = 'toast__message';
      detail.textContent = message;
      body.appendChild(detail);
    }

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'button button--ghost button--sm';
    closeBtn.setAttribute('data-toast-close', '');
    closeBtn.setAttribute('aria-label', 'Dismiss notice');
    closeBtn.textContent = 'Dismiss';

    toast.append(body, closeBtn);
    region.appendChild(toast);
  });
}

function initDataTables(root = document) {
  for (const wrapper of root.querySelectorAll('[data-table]')) {
    const tbody = wrapper.querySelector('tbody');
    if (!tbody) {
      continue;
    }
    const allRows = Array.from(tbody.querySelectorAll('tr'));
    const searchInput = wrapper.querySelector('[data-table-search]');
    const chips = Array.from(wrapper.querySelectorAll('[data-table-filter]'));
    const clearBtn = wrapper.querySelector('[data-table-clear]');
    const prevBtn = wrapper.querySelector('[data-page-prev]');
    const nextBtn = wrapper.querySelector('[data-page-next]');
    const summary = wrapper.querySelector('[data-page-summary]');
    const pageSize = Math.max(1, Number(wrapper.getAttribute('data-page-size')) || 10);

    let activeFilter = '';
    let page = 1;

    const render = () => {
      const query = (searchInput?.value || '').trim().toLowerCase();
      const matching = allRows.filter((row) => {
        const status = (row.getAttribute('data-status') || '').toLowerCase();
        const matchesChip = !activeFilter || status === activeFilter;
        const matchesQuery = !query || (row.textContent || '').toLowerCase().includes(query);
        return matchesChip && matchesQuery;
      });

      const totalPages = Math.max(1, Math.ceil(matching.length / pageSize));
      if (page > totalPages) {
        page = totalPages;
      }
      const start = (page - 1) * pageSize;
      const visible = new Set(matching.slice(start, start + pageSize));

      for (const row of allRows) {
        row.hidden = !visible.has(row);
      }

      if (summary) {
        const from = matching.length === 0 ? 0 : start + 1;
        const to = Math.min(start + pageSize, matching.length);
        summary.textContent = `Showing ${from}–${to} of ${matching.length}`;
      }
      if (prevBtn) {
        prevBtn.disabled = page <= 1;
      }
      if (nextBtn) {
        nextBtn.disabled = page >= totalPages;
      }
    };

    searchInput?.addEventListener('input', () => {
      page = 1;
      render();
    });

    for (const chip of chips) {
      chip.addEventListener('click', () => {
        const value = (chip.getAttribute('data-table-filter') || '').toLowerCase();
        activeFilter = activeFilter === value ? '' : value;
        for (const item of chips) {
          const itemVal = (item.getAttribute('data-table-filter') || '').toLowerCase();
          item.setAttribute('aria-pressed', String(activeFilter !== '' && itemVal === activeFilter));
        }
        page = 1;
        render();
      });
    }

    clearBtn?.addEventListener('click', () => {
      activeFilter = '';
      if (searchInput) {
        searchInput.value = '';
      }
      for (const item of chips) {
        item.setAttribute('aria-pressed', 'false');
      }
      page = 1;
      render();
    });

    prevBtn?.addEventListener('click', () => {
      if (page > 1) {
        page -= 1;
        render();
      }
    });

    nextBtn?.addEventListener('click', () => {
      page += 1;
      render();
    });

    for (const sortBtn of wrapper.querySelectorAll('[data-table-sort]')) {
      sortBtn.addEventListener('click', () => {
        const th = sortBtn.closest('th');
        if (!th) {
          return;
        }
        const colIndex = Array.from(th.parentElement.children).indexOf(th);
        const type = sortBtn.getAttribute('data-sort-type') || 'text';
        const currentDir = th.getAttribute('aria-sort') === 'ascending' ? 'ascending' : 'descending';
        const nextDir = currentDir === 'ascending' ? 'descending' : 'ascending';

        for (const header of th.parentElement.querySelectorAll('th[aria-sort]')) {
          header.setAttribute('aria-sort', header === th ? nextDir : 'none');
        }

        allRows.sort((a, b) => {
          const cellA = a.children[colIndex]?.getAttribute('data-sort-value') ?? a.children[colIndex]?.textContent ?? '';
          const cellB = b.children[colIndex]?.getAttribute('data-sort-value') ?? b.children[colIndex]?.textContent ?? '';
          const cmp = type === 'number'
            ? (Number(cellA) || 0) - (Number(cellB) || 0)
            : String(cellA).localeCompare(String(cellB), undefined, { numeric: true, sensitivity: 'base' });
          return nextDir === 'ascending' ? cmp : -cmp;
        });

        for (const row of allRows) {
          tbody.appendChild(row);
        }
        render();
      });
    }

    render();
  }
}

initTabs();
initSegmentedControls();
initSwitches();
initTags();
initOverlays();
initToasts();
initDataTables();
mountDeclarativeCharts();
