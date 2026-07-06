import type { MonitorSummaryItem, TenantEventRow, TenantQuickStats } from './api-client';
import { renderHistoryBar } from './history-bar';

export type MercureMonitorUpdate = {
  type: 'monitor_update';
  server_time: string;
  monitor: MonitorSummaryItem;
  event?: TenantEventRow;
  stats?: TenantQuickStats;
};

export type MercureDashboardReset = {
  type: 'dashboard_reset';
  tenant: string;
  since: string | null;
  server_time: string;
  monitors: MonitorSummaryItem[];
  stats?: TenantQuickStats;
  events?: TenantEventRow[];
};

export type MercureDashboardMessage = MercureMonitorUpdate | MercureDashboardReset;

/**
 * Applies a monitor row update on the dashboard list.
 */
export function applyMonitorUpdate(
  root: HTMLElement,
  monitor: MonitorSummaryItem,
  options?: { animateHistory?: boolean },
): void {
  if (monitor.id === null) {
    return;
  }

  const rows = root.querySelectorAll<HTMLElement>(
    `[data-monitor-id="${monitor.id}"][data-sidebar-row], [data-monitor-id="${monitor.id}"].uptime-monitor-item`,
  );
  if (rows.length === 0) {
    return;
  }

  const status = monitor.last_status ?? 'unknown';
  const pillStatus = monitor.paused ? 'paused' : status;
  const slots = Number.parseInt(root.dataset.historySlots ?? '50', 10);
  const slotCount = Number.isNaN(slots) ? 50 : slots;
  const history = monitor.history ?? [];

  rows.forEach((row) => {
    const statusCell = row.querySelector<HTMLElement>('[data-status-cell]');
    if (statusCell !== null) {
      statusCell.className = `uptime-status uptime-status-${status}`;
      statusCell.textContent = status;
    }

    const latencyCell = row.querySelector<HTMLElement>('[data-latency-cell]');
    if (latencyCell !== null) {
      latencyCell.textContent =
        monitor.last_latency_ms !== null ? `${monitor.last_latency_ms} ms` : '—';
    }

    const checkedCell = row.querySelector<HTMLElement>('[data-checked-cell]');
    if (checkedCell !== null) {
      checkedCell.textContent =
        monitor.last_checked_at !== null && monitor.last_checked_at !== undefined
          ? monitor.last_checked_at.replace('T', ' ').slice(0, 19)
          : '—';
    }

    const uptimePill = row.querySelector<HTMLElement>('[data-uptime-pill]');
    if (uptimePill !== null) {
      const hasUptime =
        !monitor.paused &&
        monitor.uptime_24h !== null &&
        monitor.uptime_24h !== undefined;
      uptimePill.textContent = hasUptime ? `${Math.round(monitor.uptime_24h as number)}%` : '—';
      uptimePill.className = row.classList.contains('uptime-sidebar-item')
        ? `uptime-sidebar-pill uptime-sidebar-pill-${pillStatus}`
        : `uptime-uptime-pill uptime-uptime-pill-${status}`;
    }

    const historyBar = row.querySelector<HTMLElement>('[data-history-bar]');
    if (historyBar !== null) {
      renderHistoryBar(historyBar, history, {
        animateLast: options?.animateHistory ?? false,
        slots: slotCount,
      });
    }

    row.classList.remove('uptime-monitor-item--flash', 'uptime-sidebar-item--flash');
    void row.offsetWidth;
    row.classList.add(
      row.classList.contains('uptime-sidebar-item') ? 'uptime-sidebar-item--flash' : 'uptime-monitor-item--flash',
    );
  });
}
