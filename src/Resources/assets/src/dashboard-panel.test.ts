import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { MonitorSummaryItem, SummaryResponse, TenantEventRow } from './api-client';
import {
  applyDashboardSnapshot,
  applyEventsFilter,
  collectDomMonitorIds,
  dashboardStructureChanged,
  initEventsFilter,
  initEventsFilterFromUrl,
  initMonitorSearch,
  initSidebarSelection,
  prependEventRow,
  readActiveEventsFilterId,
  refreshDashboardLayout,
  reloadDashboardIfStructureChanged,
  renderEventsTable,
  renderQuickStats,
  selectSidebarMonitor,
  syncDashboardLayoutIfNeeded,
  updateLastSync,
} from './dashboard-panel';

const SIDEBAR = `
  <div data-sidebar-tree>
    <div data-project-group data-project-id="prod">
      <button type="button" data-project-toggle></button>
      <ul data-project-body>
        <li data-sidebar-row data-monitor-id="1" tabindex="0"><a href="/m/1" data-sidebar-name>Storefront</a><span data-status-cell></span></li>
        <li data-sidebar-row data-monitor-id="2" tabindex="0"><a href="/m/2" data-sidebar-name>Checkout API</a><span data-status-cell></span></li>
      </ul>
    </div>
  </div>`;

function mountDashboard(attrs: Record<string, string> = {}): HTMLElement {
  document.body.innerHTML = `
    <div id="uptime-dashboard-root">
      <form data-monitor-search-form><input data-monitor-search></form>
      ${SIDEBAR}
      <section data-quick-stats><span data-stat-up>0</span><span data-stat-down>0</span><span data-stat-degraded>0</span><span data-stat-unknown>0</span><span data-stat-paused>0</span></section>
      <span data-events-filter-hint data-filter-template="Filtered by monitor: %name%" hidden></span>
      <button type="button" data-events-clear-filter hidden>Clear</button>
      <span data-last-sync>—</span>
      <table><tbody data-events-body>
        <tr data-event-row data-monitor-id="1"><td class="uptime-event-time">2026-01-01 10:00:00</td></tr>
        <tr data-event-row data-monitor-id="2"><td class="uptime-event-time">2026-01-01 10:00:00</td></tr>
      </tbody></table>
    </div>`;
  const root = document.getElementById('uptime-dashboard-root') as HTMLElement;
  Object.assign(root.dataset, attrs);
  return root;
}

function monitorItem(id: number, name: string): MonitorSummaryItem {
  return {
    id, name, type: 'http', target: 't', paused: false, interval_seconds: 60, next_check_at: null,
    last_status: 'up', last_latency_ms: 1, last_checked_at: null, last_status_code: 200, history: [], uptime_24h: 100,
  };
}

function snapshot(monitors: MonitorSummaryItem[], extra: Partial<SummaryResponse> = {}): SummaryResponse {
  return { tenant: 'main', since: null, server_time: '2026-01-01T11:00:00+00:00', monitors, ...extra };
}

function event(id: number | null, at = '2026-01-01T11:00:00+00:00', overrides: Partial<TenantEventRow> = {}): TenantEventRow {
  return { monitor_id: id, monitor_name: `<b>M${id}</b>`, status: 'down', checked_at: at, message: 'HTTP 500', status_code: 500, is_group: false, ...overrides };
}

const FRAGMENT = `
  <div data-layout-fragment-root>
    <div data-sidebar-tree><ul><li data-sidebar-row data-monitor-id="3"><a data-sidebar-name>New</a></li></ul></div>
    <section data-quick-stats><span data-stat-up>9</span></section>
    <table><tbody data-events-body><tr data-event-row data-monitor-id="3"></tr></tbody></table>
  </div>`;

beforeEach(() => {
  window.history.replaceState(null, '', '/uptime/main');
  Element.prototype.scrollIntoView = vi.fn();
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('structure detection', () => {
  it('compares sidebar ids and names with the snapshot', () => {
    const root = mountDashboard();
    expect([...collectDomMonitorIds(root)]).toEqual([1, 2]);
    expect(dashboardStructureChanged(root, snapshot([monitorItem(1, 'Storefront'), monitorItem(2, 'Checkout API')]))).toBe(false);
    expect(dashboardStructureChanged(root, snapshot([monitorItem(1, 'Storefront')]))).toBe(true);
    expect(dashboardStructureChanged(root, snapshot([monitorItem(1, 'Storefront'), monitorItem(3, 'Other')]))).toBe(true);
    expect(dashboardStructureChanged(root, snapshot([monitorItem(1, 'Storefront'), monitorItem(2, 'Renamed')]))).toBe(true);
    expect(dashboardStructureChanged(root, snapshot([monitorItem(1, 'Storefront'), { ...monitorItem(2, ''), id: 2 }]))).toBe(false);
  });
});

describe('events filter', () => {
  it('reads the active filter from the dataset, else from the URL', () => {
    const root = mountDashboard({ eventsFilter: '2' });
    expect(readActiveEventsFilterId(root)).toBe(2);
    root.dataset.eventsFilter = 'x';
    window.history.replaceState(null, '', '/uptime/main?monitor=1');
    expect(readActiveEventsFilterId(root)).toBe(1);
  });

  it('filters rows, selects the sidebar row, shows the hint and syncs the URL', () => {
    const root = mountDashboard();
    const listener = vi.fn();
    root.addEventListener('uptime:events-filter-changed', listener);

    applyEventsFilter(root, 2);

    const rows = root.querySelectorAll<HTMLElement>('[data-event-row]');
    expect(rows[0].hidden).toBe(true);
    expect(rows[1].hidden).toBe(false);
    expect(root.dataset.eventsFilter).toBe('2');
    expect(root.dataset.eventsFilterName).toBe('Checkout API');
    expect(root.querySelector('[data-sidebar-row].is-selected')?.getAttribute('data-monitor-id')).toBe('2');
    expect(root.querySelector<HTMLElement>('[data-events-filter-hint]')?.textContent).toBe('Filtered by monitor: Checkout API');
    expect(root.querySelector<HTMLElement>('[data-events-clear-filter]')?.hidden).toBe(false);
    expect(window.location.search).toBe('?monitor=2');
    expect(listener).toHaveBeenCalledOnce();

    applyEventsFilter(root, null);
    expect(rows[0].hidden).toBe(false);
    expect(root.querySelector<HTMLElement>('[data-events-filter-hint]')?.hidden).toBe(true);
    expect(root.querySelector('.is-selected')).toBeNull();
    expect(window.location.search).toBe('');
  });

  it('uses the id as hint name for an unknown monitor and keeps the URL when asked', () => {
    const root = mountDashboard();
    applyEventsFilter(root, 77, { syncUrl: false });
    expect(root.querySelector<HTMLElement>('[data-events-filter-hint]')?.textContent).toBe('Filtered by monitor: 77');
    expect(window.location.search).toBe('');
    applyEventsFilter(root, Number.NaN);
    expect(root.dataset.eventsFilter).toBe('');
  });

  it('keeps a hint without placeholder and works without hint / clear button', () => {
    const root = mountDashboard();
    const hint = root.querySelector<HTMLElement>('[data-events-filter-hint]') as HTMLElement;
    delete hint.dataset.filterTemplate;
    hint.textContent = 'Filtered';
    applyEventsFilter(root, 1);
    expect(hint.textContent).toBe('Filtered');

    hint.remove();
    root.querySelector('[data-events-clear-filter]')?.remove();
    expect(() => applyEventsFilter(root, 1)).not.toThrow();
  });

  it('applies the URL filter on init, binds the clear button and popstate', () => {
    window.history.replaceState(null, '', '/uptime/main?monitor=1');
    const root = mountDashboard();
    initEventsFilter(root);
    initEventsFilterFromUrl(root);
    expect(root.dataset.eventsFilter).toBe('1');

    (root.querySelector('[data-events-clear-filter]') as HTMLElement).click();
    expect(root.dataset.eventsFilter).toBe('');

    window.history.replaceState(null, '', '/uptime/main?monitor=2');
    window.dispatchEvent(new PopStateEvent('popstate'));
    expect(root.dataset.eventsFilter).toBe('2');

    window.history.replaceState(null, '', '/uptime/main');
    const plain = mountDashboard();
    initEventsFilterFromUrl(plain);
    expect(plain.dataset.eventsFilter).toBeUndefined();
  });

  it('captures the hint template once', () => {
    const root = mountDashboard();
    const hint = root.querySelector<HTMLElement>('[data-events-filter-hint]') as HTMLElement;
    delete hint.dataset.filterTemplate;
    hint.textContent = 'Only %name%';
    initEventsFilter(root);
    expect(hint.dataset.filterTemplate).toBe('Only %name%');
  });

  it('selects nothing for an unknown sidebar id', () => {
    const root = mountDashboard();
    selectSidebarMonitor(root, 99);
    expect(root.querySelector('.is-selected')).toBeNull();
  });
});

describe('sidebar selection and search', () => {
  it('selects a monitor by click (not on its name link) and by keyboard', () => {
    const root = mountDashboard();
    initSidebarSelection(root);
    const row = root.querySelector('[data-monitor-id="2"][data-sidebar-row]') as HTMLElement;

    (row.querySelector('a') as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    expect(root.dataset.eventsFilter).toBeUndefined();

    row.click();
    expect(root.dataset.eventsFilter).toBe('2');

    const first = root.querySelector('[data-monitor-id="1"][data-sidebar-row]') as HTMLElement;
    first.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    expect(root.dataset.eventsFilter).toBe('1');
    first.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));
    expect(root.dataset.eventsFilter).toBe('1');

    row.dataset.monitorId = 'x';
    row.dispatchEvent(new KeyboardEvent('keydown', { key: ' ' }));
    expect(root.dataset.eventsFilter).toBe('1');
  });

  it('filters the tree by name and never submits the search form', () => {
    const root = mountDashboard();
    initMonitorSearch(root);
    const input = root.querySelector('[data-monitor-search]') as HTMLInputElement;

    input.value = 'check';
    input.dispatchEvent(new Event('input'));
    expect(root.querySelector<HTMLElement>('[data-monitor-id="1"][data-sidebar-row]')?.hidden).toBe(true);
    expect(root.querySelector<HTMLElement>('[data-monitor-id="2"][data-sidebar-row]')?.hidden).toBe(false);

    input.value = 'nothing';
    input.dispatchEvent(new Event('input'));
    expect(root.querySelector<HTMLElement>('[data-project-group]')?.hidden).toBe(true);

    const submit = new Event('submit', { cancelable: true });
    root.querySelector('form')?.dispatchEvent(submit);
    expect(submit.defaultPrevented).toBe(true);

    const bare = document.createElement('div');
    expect(() => initMonitorSearch(bare)).not.toThrow();
  });
});

describe('events table, stats and sync stamp', () => {
  it('renders events escaped with translated labels, or the empty row', () => {
    const root = mountDashboard({ i18n: JSON.stringify({ no_events: 'Nothing', status: { down: 'Caído' } }) });
    renderEventsTable(root, [event(1), event(2, undefined, { message: null, status: 'weird' })]);

    const rows = root.querySelectorAll<HTMLElement>('[data-event-row]');
    expect(rows).toHaveLength(2);
    expect(rows[0].innerHTML).toContain('&lt;b&gt;M1&lt;/b&gt;');
    expect(rows[0].textContent).toContain('Caído');
    expect(rows[0].querySelector('.uptime-event-time')?.textContent).toBe('2026-01-01 11:00:00');
    expect(rows[1].textContent).toContain('weird');
    expect(rows[1].textContent).toContain('—');

    renderEventsTable(root, []);
    expect(root.querySelector('[data-events-empty]')?.textContent).toBe('Nothing');
  });

  it('falls back to the default labels on missing or invalid i18n data', () => {
    const root = mountDashboard({ i18n: '{bad' });
    renderEventsTable(root, [event(1, undefined, { status: 'up' })]);
    expect(root.textContent).toContain('Up');
    root.dataset.i18n = '';
    renderEventsTable(root, []);
    expect(root.querySelector('[data-events-empty]')?.textContent).toBe('No check events yet.');
    root.dataset.i18n = JSON.stringify({ status: {} });
    renderEventsTable(root, []);
    expect(root.querySelector('[data-events-empty]')?.textContent).toBe('No check events yet.');
  });

  it('prepends new events, skips duplicates and caps the list at 80 rows', () => {
    const root = mountDashboard();
    const tbody = root.querySelector('[data-events-body]') as HTMLElement;
    tbody.innerHTML = '<tr data-events-empty><td>none</td></tr>';

    prependEventRow(root, event(1));
    expect(tbody.querySelector('[data-events-empty]')).toBeNull();
    prependEventRow(root, event(1));
    expect(tbody.querySelectorAll('[data-event-row]')).toHaveLength(1);
    prependEventRow(root, event(null, '2026-01-01T11:05:00+00:00'));
    expect(tbody.querySelectorAll('[data-event-row]')).toHaveLength(2);

    for (let i = 0; i < 85; i += 1) {
      prependEventRow(root, event(1, `2026-01-02T00:${String(i).padStart(2, '0')}:00`));
    }
    expect(tbody.querySelectorAll('[data-event-row]').length).toBeLessThanOrEqual(80);
  });

  it('updates stats and the last sync stamp, ignoring missing nodes', () => {
    const root = mountDashboard();
    renderQuickStats(root, { up: 4, down: 1, degraded: 0, unknown: 2, paused: 3 });
    expect(root.querySelector('[data-stat-up]')?.textContent).toBe('4');
    expect(root.querySelector('[data-stat-paused]')?.textContent).toBe('3');
    updateLastSync(root, '2026-01-01T12:34:56+00:00');
    expect(root.querySelector('[data-last-sync]')?.textContent).toBe('2026-01-01 12:34:56');

    const bare = document.createElement('div');
    expect(() => {
      renderEventsTable(bare, []);
      prependEventRow(bare, event(1));
      updateLastSync(bare, 'x');
      renderQuickStats(bare, { up: 0, down: 0, degraded: 0, unknown: 0, paused: 0 });
    }).not.toThrow();
  });
});

describe('layout refresh', () => {
  it('replaces tree, stats and events from the fragment and keeps the filter', async () => {
    const root = mountDashboard({ layoutFragment: '/uptime/main/fragment/layout', eventsFilter: '3' });
    const fetchMock = vi.fn().mockResolvedValue(new Response(FRAGMENT, { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);

    await expect(refreshDashboardLayout(root)).resolves.toBe(true);
    expect(fetchMock).toHaveBeenCalledWith('/uptime/main/fragment/layout?monitor=3', expect.anything());
    expect(root.querySelector('[data-sidebar-name]')?.textContent).toBe('New');
    expect(root.querySelector('[data-stat-up]')?.textContent).toBe('9');
    expect(root.querySelector('[data-sidebar-row].is-selected')?.getAttribute('data-monitor-id')).toBe('3');
  });

  it('refreshes without a filter', async () => {
    const root = mountDashboard({ layoutFragment: '/frag' });
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(FRAGMENT)));
    await expect(refreshDashboardLayout(root)).resolves.toBe(true);
    expect(root.dataset.eventsFilter).toBe('');
  });

  it('reports failure without URL, on HTTP errors and without fragment root', async () => {
    const root = mountDashboard();
    await expect(refreshDashboardLayout(root)).resolves.toBe(false);

    root.dataset.layoutFragment = '/frag';
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('', { status: 500 })));
    await expect(refreshDashboardLayout(root)).resolves.toBe(false);

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<p>nope</p>')));
    await expect(refreshDashboardLayout(root)).resolves.toBe(false);
  });

  it('syncs the layout only when the structure changed and reloads when refresh fails', async () => {
    const reload = vi.fn();
    vi.stubGlobal('location', { ...window.location, reload, search: '', href: window.location.href });
    const root = mountDashboard({ layoutFragment: '/frag' });
    const same = snapshot([monitorItem(1, 'Storefront'), monitorItem(2, 'Checkout API')]);
    await expect(syncDashboardLayoutIfNeeded(root, same)).resolves.toBe(false);

    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
    await expect(syncDashboardLayoutIfNeeded(root, snapshot([]))).resolves.toBe(true);
    expect(reload).toHaveBeenCalledOnce();

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('', { status: 500 })));
    await expect(syncDashboardLayoutIfNeeded(root, snapshot([]))).resolves.toBe(true);
    expect(reload).toHaveBeenCalledTimes(2);

    expect(reloadDashboardIfStructureChanged(root, same)).toBe(false);
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(FRAGMENT)));
    expect(reloadDashboardIfStructureChanged(root, snapshot([]))).toBe(true);
  });
});

describe('applyDashboardSnapshot', () => {
  it('updates rows, stats, events (with the active filter) and the sync stamp', async () => {
    const root = mountDashboard({ eventsFilter: '2' });
    await applyDashboardSnapshot(
      root,
      snapshot([monitorItem(1, 'Storefront'), monitorItem(2, 'Checkout API')], {
        stats: { up: 2, down: 0, degraded: 0, unknown: 0, paused: 0 },
        events: [event(1), event(2)],
      }),
    );

    expect(root.querySelector('[data-monitor-id="1"] [data-status-cell]')?.textContent).toBe('up');
    expect(root.querySelector('[data-stat-up]')?.textContent).toBe('2');
    expect(root.querySelectorAll<HTMLElement>('[data-event-row]')[0].hidden).toBe(true);
    expect(root.querySelector('[data-last-sync]')?.textContent).toBe('2026-01-01 11:00:00');
  });

  it('updates rows without stats / events / filter', async () => {
    const root = mountDashboard();
    await applyDashboardSnapshot(root, snapshot([monitorItem(1, 'Storefront'), monitorItem(2, 'Checkout API')], { events: [] }));
    expect(root.querySelector('[data-events-empty]')).not.toBeNull();
    await applyDashboardSnapshot(root, snapshot([monitorItem(1, 'Storefront'), monitorItem(2, 'Checkout API')]));
    expect(root.querySelector('[data-last-sync]')?.textContent).toBe('2026-01-01 11:00:00');
  });

  it('only stamps the sync time after a layout refresh', async () => {
    const root = mountDashboard({ layoutFragment: '/frag' });
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(FRAGMENT)));
    await applyDashboardSnapshot(root, snapshot([monitorItem(3, 'New')]));
    expect(root.querySelector('[data-last-sync]')?.textContent).toBe('2026-01-01 11:00:00');
  });
});
