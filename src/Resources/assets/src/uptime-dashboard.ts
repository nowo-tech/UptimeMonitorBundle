import '../scss/uptime-dashboard.scss';
import { fetchSummary } from './api-client';
import { applyMonitorUpdate } from './dashboard-update';
import {
  applyDashboardSnapshot,
  applyEventsFilter,
  initEventsFilter,
  initEventsFilterFromUrl,
  initMonitorSearch,
  initSidebarSelection,
  syncDashboardLayoutIfNeeded,
  renderEventsTable,
  renderQuickStats,
  updateLastSync,
} from './dashboard-panel';
import {
  initLiveIndicator,
  initProjectCollapse,
  pulseLiveIndicator,
  setPollingFallbackBadge,
} from './dashboard-ui';
import { subscribeMercure } from './mercure-subscriber';
import { createBundleLogger, setBundleLogger, getLogger } from './uptime-logger';
import { initMonitorFormModal } from './monitor-form-modal';

async function syncFromSummary(root: HTMLElement, apiUrl: string): Promise<void> {
  const data = await fetchSummary(apiUrl);
  await applyDashboardSnapshot(root, data);
}

function bootstrapPolling(root: HTMLElement, apiUrl: string, interval: number): void {
  const liveBadge = initLiveIndicator(root);
  let lastServerTime = '';

  const tick = async (): Promise<void> => {
    try {
      const data = await fetchSummary(apiUrl);
      if (await syncDashboardLayoutIfNeeded(root, data)) {
        updateLastSync(root, data.server_time);
        pulseLiveIndicator(liveBadge);
        return;
      }

      const animate = data.server_time !== lastServerTime;
      lastServerTime = data.server_time;

      for (const monitor of data.monitors) {
        applyMonitorUpdate(root, monitor, { animateHistory: animate });
      }

      if (data.stats !== undefined) {
        renderQuickStats(root, data.stats);
      }

      if (data.events !== undefined) {
        const filterRaw = root.dataset.eventsFilter;
        const filterId = filterRaw !== undefined && filterRaw !== '' ? Number.parseInt(filterRaw, 10) : null;
        renderEventsTable(root, data.events);
        if (filterId !== null && !Number.isNaN(filterId)) {
          applyEventsFilter(root, filterId);
        }
      }

      updateLastSync(root, data.server_time);

      pulseLiveIndicator(liveBadge);
    } catch {
      // Keep server-rendered state on API errors.
    }
  };

  void tick();
  window.setInterval(() => void tick(), interval);
}

function bootstrapMercure(root: HTMLElement, hubUrl: string, topic: string): void {
  const liveBadge = initLiveIndicator(root);
  const apiUrl = root.dataset.apiSummary ?? '';
  const interval = Number.parseInt(root.dataset.pollInterval ?? '30000', 10);
  let pollingFallbackStarted = false;
  let fallbackTimer: ReturnType<typeof setTimeout> | null = null;

  root.dataset.transport = 'mercure';
  getLogger().info('Transport: Mercure (SSE)', { hub: hubUrl, topic });

  const startPollingFallback = (): void => {
    if (pollingFallbackStarted || apiUrl === '') {
      return;
    }
    pollingFallbackStarted = true;
    root.dataset.transport = 'polling';
    setPollingFallbackBadge(root, interval);
    bootstrapPolling(root, apiUrl, interval);
  };

  // One-shot layout check on load only (not periodic summary while Mercure is up).
  if (apiUrl !== '') {
    void syncFromSummary(root, apiUrl).catch(() => {
      // Keep server-rendered state on API errors.
    });
  }

  subscribeMercure(root, hubUrl, topic, {
    onOpen: () => {
      if (fallbackTimer !== null) {
        clearTimeout(fallbackTimer);
        fallbackTimer = null;
      }
      pulseLiveIndicator(liveBadge);
    },
    onMessage: () => {
      pulseLiveIndicator(liveBadge);
    },
    onError: () => {
      if (fallbackTimer !== null) {
        clearTimeout(fallbackTimer);
      }
      fallbackTimer = setTimeout(startPollingFallback, 4000);
    },
  });
}

function bootstrap(): void {
  const root = document.getElementById('uptime-dashboard-root');
  if (root === null) {
    return;
  }

  const log = createBundleLogger('uptime', {
    buildTime:
      typeof __UPTIME_ASSETS_BUILD_TIME__ !== 'undefined'
        ? __UPTIME_ASSETS_BUILD_TIME__
        : new Date().toISOString(),
    alwaysLog: true,
  });
  setBundleLogger(log);
  log.scriptLoaded();

  initProjectCollapse(root);
  initMonitorSearch(root);
  initSidebarSelection(root);
  initEventsFilter(root);
  initEventsFilterFromUrl(root);
  initMonitorFormModal(document);

  const sync = root.dataset.sync ?? 'polling';
  const mercureHub = root.dataset.mercureHub ?? '';
  const mercureTopic = root.dataset.mercureTopic ?? '';

  if (sync === 'mercure' && mercureHub !== '' && mercureTopic !== '') {
    bootstrapMercure(root, mercureHub, mercureTopic);
    return;
  }

  const apiUrl = root.dataset.apiSummary ?? '';
  const interval = Number.parseInt(root.dataset.pollInterval ?? '30000', 10);
  if (apiUrl === '') {
    return;
  }

  root.dataset.transport = 'polling';
  getLogger().info('Transport: polling (summary API)', { interval_ms: interval });
  bootstrapPolling(root, apiUrl, interval);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootstrap);
} else {
  bootstrap();
}
