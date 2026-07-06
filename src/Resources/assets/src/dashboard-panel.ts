import type { SummaryResponse, TenantEventRow, TenantQuickStats } from './api-client';
import {
  appendMonitorFilterToUrl,
  readMonitorFilterFromUrl,
  writeMonitorFilterToUrl,
} from './dashboard-events-url';
import { applyMonitorUpdate } from './dashboard-update';
import { initProjectCollapse } from './dashboard-ui';

/** Monitor IDs rendered in the sidebar (groups + children). */
export function collectDomMonitorIds(root: HTMLElement): Set<number> {
  const ids = new Set<number>();
  root.querySelectorAll<HTMLElement>('[data-sidebar-row]').forEach((row) => {
    const id = Number.parseInt(row.dataset.monitorId ?? '', 10);
    if (!Number.isNaN(id)) {
      ids.add(id);
    }
  });

  return ids;
}

function collectSnapshotMonitorIds(data: SummaryResponse): Set<number> {
  const ids = new Set<number>();
  for (const monitor of data.monitors) {
    if (monitor.id !== null) {
      ids.add(monitor.id);
    }
  }

  return ids;
}

function collectDomMonitorNames(root: HTMLElement): Set<string> {
  const names = new Set<string>();
  root.querySelectorAll<HTMLElement>('[data-sidebar-name]').forEach((el) => {
    const name = el.textContent?.trim();
    if (name !== undefined && name !== '') {
      names.add(name);
    }
  });

  return names;
}

/**
 * True when the API monitor tree no longer matches the server-rendered sidebar (e.g. after seed --fresh).
 */
export function dashboardStructureChanged(root: HTMLElement, data: SummaryResponse): boolean {
  const dom = collectDomMonitorIds(root);
  const snapshot = collectSnapshotMonitorIds(data);

  if (dom.size !== snapshot.size) {
    return true;
  }

  for (const id of snapshot) {
    if (!dom.has(id)) {
      return true;
    }
  }

  const domNames = collectDomMonitorNames(root);
  for (const monitor of data.monitors) {
    if (monitor.name !== '' && !domNames.has(monitor.name)) {
      return true;
    }
  }

  return false;
}

/** Re-bind sidebar interactions after replacing layout HTML. */
export function rebindDashboardUi(root: HTMLElement): void {
  initProjectCollapse(root);
  initMonitorSearch(root);
  initSidebarSelection(root);
}

/**
 * Fetches server-rendered sidebar, stats and events (post-seed layout).
 */
export function readActiveEventsFilterId(root: HTMLElement): number | null {
  const fromDataset = root.dataset.eventsFilter ?? '';
  if (fromDataset !== '') {
    const id = Number.parseInt(fromDataset, 10);
    if (!Number.isNaN(id) && id > 0) {
      return id;
    }
  }

  return readMonitorFilterFromUrl();
}

export function selectSidebarMonitor(root: HTMLElement, monitorId: number | null): void {
  root.querySelectorAll<HTMLElement>('[data-sidebar-row].is-selected').forEach((el) => {
    el.classList.remove('is-selected');
  });

  if (monitorId === null) {
    return;
  }

  const row = root.querySelector<HTMLElement>(`[data-sidebar-row][data-monitor-id="${monitorId}"]`);
  if (row === null) {
    return;
  }

  row.classList.add('is-selected');
  row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function updateEventsFilterHint(root: HTMLElement, monitorId: number | null): void {
  const hint = root.querySelector<HTMLElement>('[data-events-filter-hint]');
  if (hint === null) {
    return;
  }

  if (monitorId === null) {
    hint.hidden = true;
    return;
  }

  const name = root.dataset.eventsFilterName ?? '';
  const template = hint.dataset.filterTemplate ?? hint.textContent ?? '';
  hint.textContent = template.includes('%name%')
    ? template.replace('%name%', name !== '' ? name : String(monitorId))
    : template;
  hint.hidden = false;
}

export async function refreshDashboardLayout(root: HTMLElement): Promise<boolean> {
  const baseUrl = root.dataset.layoutFragment ?? '';
  const filterId = readActiveEventsFilterId(root);
  const url = appendMonitorFilterToUrl(baseUrl, filterId);
  if (url === '') {
    return false;
  }

  const response = await fetch(url, {
    headers: { Accept: 'text/html' },
    credentials: 'same-origin',
  });
  if (!response.ok) {
    return false;
  }

  const html = await response.text();
  const doc = new DOMParser().parseFromString(html, 'text/html');
  const fragmentRoot = doc.querySelector<HTMLElement>('[data-layout-fragment-root]');
  if (fragmentRoot === null) {
    return false;
  }

  const newTree = fragmentRoot.querySelector<HTMLElement>('[data-sidebar-tree]');
  const currentTree = root.querySelector<HTMLElement>('[data-sidebar-tree]');
  if (newTree !== null && currentTree !== null) {
    currentTree.replaceWith(document.importNode(newTree, true));
  }

  const newStats = fragmentRoot.querySelector<HTMLElement>('[data-quick-stats]');
  const currentStats = root.querySelector<HTMLElement>('[data-quick-stats]');
  if (newStats !== null && currentStats !== null) {
    currentStats.replaceWith(document.importNode(newStats, true));
  }

  const newEvents = fragmentRoot.querySelector<HTMLElement>('[data-events-body]');
  const currentEvents = root.querySelector<HTMLElement>('[data-events-body]');
  if (newEvents !== null && currentEvents !== null) {
    currentEvents.replaceWith(document.importNode(newEvents, true));
  }

  if (filterId !== null) {
    applyEventsFilter(root, filterId, { syncUrl: false });
    selectSidebarMonitor(root, filterId);
  } else {
    applyEventsFilter(root, null, { syncUrl: false });
  }

  rebindDashboardUi(root);

  return true;
}

/** Refresh layout HTML when monitors changed; falls back to full reload. */
export async function syncDashboardLayoutIfNeeded(
  root: HTMLElement,
  data: SummaryResponse,
): Promise<boolean> {
  if (!dashboardStructureChanged(root, data)) {
    return false;
  }

  try {
    if (await refreshDashboardLayout(root)) {
      return true;
    }
  } catch {
    // Fall through to hard reload.
  }

  window.location.reload();

  return true;
}

/** @deprecated Use syncDashboardLayoutIfNeeded */
export function reloadDashboardIfStructureChanged(root: HTMLElement, data: SummaryResponse): boolean {
  if (!dashboardStructureChanged(root, data)) {
    return false;
  }

  void syncDashboardLayoutIfNeeded(root, data);

  return true;
}

const DEFAULT_STATUS_LABELS: Record<string, string> = {
  up: 'Up',
  down: 'Down',
  degraded: 'Degraded',
  unknown: 'Unknown',
  paused: 'Paused',
};

type DashboardI18n = {
  no_events?: string;
  status?: Record<string, string>;
};

function readDashboardI18n(root: HTMLElement): { noEvents: string; status: Record<string, string> } {
  const raw = root.dataset.i18n ?? '';
  if (raw === '') {
    return { noEvents: 'No check events yet.', status: DEFAULT_STATUS_LABELS };
  }

  try {
    const parsed = JSON.parse(raw) as DashboardI18n;

    return {
      noEvents: parsed.no_events ?? 'No check events yet.',
      status: { ...DEFAULT_STATUS_LABELS, ...parsed.status },
    };
  } catch {
    return { noEvents: 'No check events yet.', status: DEFAULT_STATUS_LABELS };
  }
}

export async function applyDashboardSnapshot(root: HTMLElement, data: SummaryResponse): Promise<void> {
  if (await syncDashboardLayoutIfNeeded(root, data)) {
    updateLastSync(root, data.server_time);
    return;
  }

  for (const monitor of data.monitors) {
    applyMonitorUpdate(root, monitor, { animateHistory: false });
  }

  if (data.stats !== undefined) {
    renderQuickStats(root, data.stats);
  }

  if (data.events !== undefined) {
    const filterRaw = root.dataset.eventsFilter;
    const filterId =
      filterRaw !== undefined && filterRaw !== '' ? Number.parseInt(filterRaw, 10) : null;
    renderEventsTable(root, data.events);
    if (filterId !== null && !Number.isNaN(filterId)) {
      applyEventsFilter(root, filterId);
    }
  }

  updateLastSync(root, data.server_time);
}

export function prependEventRow(root: HTMLElement, event: TenantEventRow): void {
  const tbody = root.querySelector<HTMLElement>('[data-events-body]');
  if (tbody === null) {
    return;
  }

  const { status: statusLabels } = readDashboardI18n(root);

  const empty = tbody.querySelector('[data-events-empty]');
  empty?.remove();

  const existing = tbody.querySelector<HTMLTableRowElement>(
    `[data-event-row][data-monitor-id="${event.monitor_id ?? ''}"]`,
  );
  if (existing !== null) {
    const existingTime = existing.querySelector('.uptime-event-time')?.textContent ?? '';
    const newTime = event.checked_at.replace('T', ' ').slice(0, 19);
    if (existingTime === newTime) {
      return;
    }
  }

  tbody.prepend(buildEventRow(event, statusLabels));

  const rows = tbody.querySelectorAll<HTMLTableRowElement>('[data-event-row]');
  if (rows.length > 80) {
    rows[rows.length - 1]?.remove();
  }

  applyEventsFilter(root);
}

export function updateLastSync(root: HTMLElement, serverTime: string): void {
  const stamp = root.querySelector<HTMLElement>('[data-last-sync]');
  if (stamp !== null) {
    stamp.textContent = serverTime.replace('T', ' ').slice(0, 19);
  }
}

export function renderQuickStats(root: HTMLElement, stats: TenantQuickStats): void {
  const map: Record<keyof TenantQuickStats, string> = {
    up: '[data-stat-up]',
    down: '[data-stat-down]',
    degraded: '[data-stat-degraded]',
    unknown: '[data-stat-unknown]',
    paused: '[data-stat-paused]',
  };

  for (const [key, selector] of Object.entries(map)) {
    const el = root.querySelector<HTMLElement>(selector);
    if (el !== null) {
      el.textContent = String(stats[key as keyof TenantQuickStats]);
    }
  }
}

function formatEventTime(iso: string): string {
  return iso.replace('T', ' ').slice(0, 19);
}

function buildEventRow(event: TenantEventRow, statusLabels: Record<string, string>): HTMLTableRowElement {
  const row = document.createElement('tr');
  row.dataset.eventRow = '';
  row.dataset.monitorId = String(event.monitor_id ?? '');
  row.dataset.status = event.status;

  const statusLabel = statusLabels[event.status] ?? event.status;

  row.innerHTML = `
    <td class="uptime-event-name">${escapeHtml(event.monitor_name)}</td>
    <td><span class="uptime-status-badge uptime-status-badge-${event.status}">${statusLabel}</span></td>
    <td class="uptime-event-time">${formatEventTime(event.checked_at)}</td>
    <td class="uptime-event-message">${escapeHtml(event.message ?? '—')}</td>
  `;

  return row;
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

export function renderEventsTable(root: HTMLElement, events: TenantEventRow[]): void {
  const tbody = root.querySelector<HTMLElement>('[data-events-body]');
  if (tbody === null) {
    return;
  }

  const { noEvents, status: statusLabels } = readDashboardI18n(root);

  tbody.replaceChildren();

  if (events.length === 0) {
    const empty = document.createElement('tr');
    empty.dataset.eventsEmpty = '';
    empty.innerHTML = `<td colspan="4">${escapeHtml(noEvents)}</td>`;
    tbody.appendChild(empty);
    return;
  }

  for (const event of events) {
    tbody.appendChild(buildEventRow(event, statusLabels));
  }

  applyEventsFilter(root);
}

export function applyEventsFilter(
  root: HTMLElement,
  monitorId?: number | null,
  options: { syncUrl?: boolean } = {},
): void {
  const syncUrl = options.syncUrl ?? true;
  let numericId: number | null;
  if (monitorId !== undefined) {
    numericId = monitorId !== null && !Number.isNaN(monitorId) ? monitorId : null;
  } else {
    numericId = readActiveEventsFilterId(root);
  }
  const filterId = numericId !== null ? String(numericId) : null;
  const clearBtn = root.querySelector<HTMLElement>('[data-events-clear-filter]');

  if (clearBtn !== null) {
    clearBtn.hidden = filterId === null;
  }

  if (numericId !== null) {
    const row = root.querySelector<HTMLElement>(`[data-sidebar-row][data-monitor-id="${numericId}"]`);
    const name = row?.querySelector('[data-sidebar-name]')?.textContent?.trim() ?? '';
    if (name !== '') {
      root.dataset.eventsFilterName = name;
    }
    selectSidebarMonitor(root, numericId);
  } else {
    delete root.dataset.eventsFilterName;
    selectSidebarMonitor(root, null);
  }

  updateEventsFilterHint(root, numericId);

  root.querySelectorAll<HTMLElement>('[data-event-row]').forEach((row) => {
    const rowId = row.dataset.monitorId ?? '';
    const show = filterId === null || rowId === filterId;
    row.hidden = !show;
  });

  root.dataset.eventsFilter = filterId ?? '';

  root.dispatchEvent(
    new CustomEvent('uptime:events-filter-changed', {
      detail: { monitorId: numericId },
    }),
  );

  if (syncUrl) {
    writeMonitorFilterToUrl(numericId);
  }
}

export function initEventsFilterFromUrl(root: HTMLElement): void {
  const filterId = readActiveEventsFilterId(root);
  if (filterId === null) {
    return;
  }

  applyEventsFilter(root, filterId);
}

export function initEventsFilter(root: HTMLElement): void {
  const hint = root.querySelector<HTMLElement>('[data-events-filter-hint]');
  if (hint !== null && hint.dataset.filterTemplate === undefined) {
    hint.dataset.filterTemplate = hint.textContent ?? '';
  }

  const clearBtn = root.querySelector<HTMLElement>('[data-events-clear-filter]');
  clearBtn?.addEventListener('click', () => {
    applyEventsFilter(root, null);
  });

  window.addEventListener('popstate', () => {
    const filterId = readMonitorFilterFromUrl();
    applyEventsFilter(root, filterId, { syncUrl: false });
  });
}

export function initSidebarSelection(root: HTMLElement): void {
  root.querySelectorAll<HTMLElement>('[data-sidebar-row]').forEach((row) => {
    const select = (): void => {
      const id = Number.parseInt(row.dataset.monitorId ?? '', 10);
      if (Number.isNaN(id)) {
        return;
      }

      applyEventsFilter(root, id);
    };

    row.addEventListener('click', (event) => {
      if ((event.target as HTMLElement).closest('a[data-sidebar-name]')) {
        return;
      }
      event.preventDefault();
      select();
    });

    row.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        select();
      }
    });
  });
}

export function initMonitorSearch(root: HTMLElement): void {
  const input = root.querySelector<HTMLInputElement>('[data-monitor-search]');
  if (input === null) {
    return;
  }

  input.addEventListener('input', () => {
    const query = input.value.trim().toLowerCase();
    root.querySelectorAll<HTMLElement>('[data-sidebar-row]').forEach((row) => {
      const name = row.querySelector('[data-sidebar-name]')?.textContent?.toLowerCase() ?? '';
      row.hidden = query !== '' && !name.includes(query);
    });

    root.querySelectorAll<HTMLElement>('[data-project-group]').forEach((group) => {
      const visible = group.querySelectorAll<HTMLElement>('[data-sidebar-row]:not([hidden])').length > 0;
      (group as HTMLElement).hidden = query !== '' && !visible;
    });
  });
}
