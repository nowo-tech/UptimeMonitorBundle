import { Chart } from 'chart.js/auto';
import { UPTIME_CHART_COLORS } from './chart-theme';
import { renderHistoryBar } from './history-bar';
import { initMonitorFormModal } from './monitor-form-modal';
import { renderPushCharts } from './push-health';

type SeriesData = {
  labels: string[];
  uptime_percent: number[];
  latency_avg_ms: number[];
};

type LatencySeries = {
  labels: string[];
  latency_ms: number[];
};

export type HistoryPayload = {
  last_status: string | null;
  last_checked_at: string | null;
  uptime_24h: number | null;
  uptime_30d: number | null;
  history: { status: string; checked_at: string }[];
  latency_series: LatencySeries;
};

/** JSON of a data attribute, null when missing or invalid. Exported for tests. */
export function parseJson<T>(raw: string | undefined): T | null {
  if (raw === undefined || raw === '') {
    return null;
  }
  try {
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}

function renderResponseChart(series: LatencySeries): void {
  const canvas = document.getElementById('uptime-chart-response');
  if (!(canvas instanceof HTMLCanvasElement) || series.labels.length === 0) {
    return;
  }

  new Chart(canvas, {
    type: 'line',
    data: {
      labels: series.labels,
      datasets: [{
        label: 'Response ms',
        data: series.latency_ms,
        borderColor: UPTIME_CHART_COLORS.borderLatency,
        backgroundColor: UPTIME_CHART_COLORS.latency,
        fill: true,
        tension: 0.2,
        pointRadius: 2,
      }],
    },
    options: {
      plugins: { legend: { display: false } },
      scales: {
        x: { ticks: { maxTicksLimit: 12 } },
        y: { beginAtZero: true },
      },
    },
  });
}

function renderAggregateCharts(series: SeriesData): void {
  const uptimeCanvas = document.getElementById('uptime-chart-uptime');
  if (uptimeCanvas instanceof HTMLCanvasElement && series.labels.length > 0) {
    new Chart(uptimeCanvas, {
      type: 'line',
      data: {
        labels: series.labels,
        datasets: [{
          label: 'Uptime %',
          data: series.uptime_percent,
          borderColor: UPTIME_CHART_COLORS.borderUptime,
          backgroundColor: UPTIME_CHART_COLORS.uptime,
          fill: true,
          tension: 0.2,
        }],
      },
      options: {
        scales: { y: { min: 0, max: 100 } },
        plugins: { legend: { display: false } },
      },
    });
  }

  const latencyCanvas = document.getElementById('uptime-chart-latency');
  if (latencyCanvas instanceof HTMLCanvasElement && series.labels.length > 0) {
    new Chart(latencyCanvas, {
      type: 'bar',
      data: {
        labels: series.labels,
        datasets: [{
          label: 'Latency ms',
          data: series.latency_avg_ms,
          backgroundColor: UPTIME_CHART_COLORS.latency,
          borderColor: UPTIME_CHART_COLORS.borderLatency,
        }],
      },
      options: {
        plugins: { legend: { display: false } },
      },
    });
  }
}

/** Applies the history API payload to the detail page. Exported for tests. */
export function applyHistoryPayload(root: HTMLElement, payload: HistoryPayload): void {
  const status = payload.last_status ?? 'unknown';
  const statusEl = root.querySelector<HTMLElement>('[data-detail-status]');
  if (statusEl !== null) {
    statusEl.className = `uptime-detail-status uptime-status-${status}`;
    statusEl.textContent = status;
  }

  const checkedEl = root.querySelector<HTMLElement>('[data-detail-checked]');
  if (checkedEl !== null && payload.last_checked_at !== null) {
    checkedEl.textContent = payload.last_checked_at.replace('T', ' ').slice(0, 19);
  }

  // The first check of a new monitor: the history, figures, charts and log replace the "not checked yet" notice.
  if (payload.last_checked_at !== null) {
    root.querySelector('[data-detail-unchecked]')?.remove();
    root.querySelectorAll<HTMLElement>('[data-detail-pending]').forEach((pending) => {
      pending.hidden = false;
    });
  }

  const uptime24 = root.querySelector<HTMLElement>('[data-uptime-24h]');
  if (uptime24 !== null) {
    uptime24.textContent =
      payload.uptime_24h !== null ? `${payload.uptime_24h.toFixed(1)}%` : '—';
  }

  const uptime30 = root.querySelector<HTMLElement>('[data-uptime-30d]');
  if (uptime30 !== null) {
    uptime30.textContent =
      payload.uptime_30d !== null ? `${payload.uptime_30d.toFixed(1)}%` : '—';
  }

  const historyBar = root.querySelector<HTMLElement>('[data-history-bar]');
  if (historyBar !== null) {
    renderHistoryBar(historyBar, payload.history, { animateLast: true });
  }
}

/** One history API poll; keeps the server-rendered state on errors. Exported for tests. */
export async function pollHistory(root: HTMLElement, apiUrl: string): Promise<void> {
  try {
    const response = await fetch(apiUrl, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
      return;
    }
    const payload = (await response.json()) as HistoryPayload;
    applyHistoryPayload(root, payload);
  } catch {
    // Keep server-rendered state on API errors.
  }
}

/** Charts, history polling and the monitor dialog of the detail page. Exported for tests. */
export function bootstrap(): void {
  const root = document.getElementById('uptime-monitor-detail-root');
  if (root === null) {
    return;
  }

  const series = parseJson<SeriesData>(root.dataset.series);
  if (series !== null) {
    renderAggregateCharts(series);
  }

  const latencySeries = parseJson<LatencySeries>(root.dataset.latencySeries);
  if (latencySeries !== null) {
    renderResponseChart(latencySeries);
  }

  renderPushCharts(root);

  const apiUrl = root.dataset.apiHistory ?? '';
  if (apiUrl !== '') {
    const tick = (): void => {
      void pollHistory(root, apiUrl);
    };
    void tick();
    window.setInterval(tick, 15000);
  }

  initMonitorFormModal(document);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootstrap);
} else {
  bootstrap();
}
