import { getLogger } from './uptime-logger';

const COLLAPSE_STORAGE_KEY = 'uptime-project-collapsed';

export type MercureConnectionContext = {
  hub?: string;
  topic?: string;
};

function loadCollapsedSet(): Set<string> {
  try {
    const raw = sessionStorage.getItem(COLLAPSE_STORAGE_KEY);
    if (raw === null || raw === '') {
      return new Set();
    }
    return new Set(JSON.parse(raw) as string[]);
  } catch {
    return new Set();
  }
}

function saveCollapsedSet(collapsed: Set<string>): void {
  sessionStorage.setItem(COLLAPSE_STORAGE_KEY, JSON.stringify([...collapsed]));
}

/**
 * Binds collapsible project groups in the sidebar tree.
 */
export function initProjectCollapse(root: HTMLElement): void {
  const collapsed = loadCollapsedSet();

  root.querySelectorAll<HTMLElement>('[data-project-group]').forEach((section) => {
    const id = section.dataset.projectId ?? '';
    const toggle = section.querySelector<HTMLElement>('[data-project-toggle]');
    const body = section.querySelector<HTMLElement>('[data-project-body]');
    if (toggle === null || body === null || id === '') {
      return;
    }

    const applyState = (isCollapsed: boolean): void => {
      section.classList.toggle('is-collapsed', isCollapsed);
      toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
    };

    applyState(collapsed.has(id));

    const toggleCollapse = (): void => {
      const willCollapse = !section.classList.contains('is-collapsed');
      applyState(willCollapse);
      if (willCollapse) {
        collapsed.add(id);
      } else {
        collapsed.delete(id);
      }
      saveCollapsedSet(collapsed);
    };

    toggle.addEventListener('click', (event) => {
      const target = event.target as HTMLElement;
      if (target.closest('a, [data-history-bar], [data-sidebar-row]')) {
        return;
      }
      toggleCollapse();
    });

    const chevron = toggle.querySelector<HTMLElement>('[data-chevron-toggle], .uptime-sidebar-chevron');
    chevron?.addEventListener('click', (event) => {
      event.stopPropagation();
      toggleCollapse();
    });
  });
}

/**
 * Returns the live polling indicator element on the dashboard toolbar.
 */
export function initLiveIndicator(root: HTMLElement): HTMLElement | null {
  return root.querySelector<HTMLElement>('[data-live-indicator]');
}

export function pulseLiveIndicator(badge: HTMLElement | null): void {
  if (badge === null) {
    return;
  }
  badge.classList.remove('is-pulsing');
  void badge.offsetWidth;
  badge.classList.add('is-pulsing');
}

export type MercureConnectionState = 'connecting' | 'connected' | 'error' | 'disconnected';

const MERCURE_BADGE_LABELS: Record<MercureConnectionState, string> = {
  connecting: 'Mercure · connecting…',
  connected: 'Mercure · connected',
  error: 'Mercure · connection error',
  disconnected: 'Mercure · disconnected',
};

/** Sync badge in the toolbar (sibling of {@code #uptime-dashboard-root}). */
export function findSyncBadge(root: HTMLElement): HTMLElement | null {
  const bar = root.previousElementSibling;
  if (bar instanceof HTMLElement && bar.classList.contains('uptime-sync-bar')) {
    return bar.querySelector<HTMLElement>('[data-sync-badge]');
  }

  return document.querySelector<HTMLElement>('[data-sync-badge]');
}

/** Updates the Mercure connection label and logs once when the SSE stream opens. */
export function setMercureConnectionState(
  root: HTMLElement,
  state: MercureConnectionState,
  context?: MercureConnectionContext,
): void {
  const badge = findSyncBadge(root);
  if (badge === null) {
    return;
  }

  badge.dataset.mercureState = state;
  badge.textContent = MERCURE_BADGE_LABELS[state];
  badge.classList.toggle('is-mercure-connected', state === 'connected');
  badge.classList.toggle('is-mercure-error', state === 'error');

  const detail =
    context?.hub !== undefined || context?.topic !== undefined
      ? { hub: context.hub ?? '', topic: context.topic ?? '' }
      : undefined;

  const log = getLogger();
  const logWithDetail = (level: 'info' | 'warn', message: string): void => {
    if (detail !== undefined) {
      log[level](message, detail);
    } else {
      log[level](message);
    }
  };

  if (state === 'connected') {
    badge.title = 'Mercure SSE connected';
    logWithDetail('info', 'Mercure connected.');
  } else if (state === 'connecting') {
    badge.title = 'Opening Mercure SSE stream…';
    logWithDetail('info', 'Mercure connecting…');
  } else if (state === 'error') {
    badge.title = 'Mercure SSE failed; check hub URL and authorization cookie';
    logWithDetail('warn', 'Mercure connection error.');
  } else {
    badge.title = 'Mercure SSE closed';
    logWithDetail('info', 'Mercure disconnected.');
  }
}

/** Badge + log when falling back to HTTP summary polling. */
export function setPollingFallbackBadge(root: HTMLElement, intervalMs: number): void {
  const badge = findSyncBadge(root);
  if (badge === null) {
    return;
  }

  const seconds = Math.round(intervalMs / 1000);
  badge.dataset.mercureState = 'polling-fallback';
  badge.textContent = `Polling · ${seconds}s`;
  badge.classList.remove('is-mercure-connected');
  badge.title = 'Mercure unavailable; using GET /summary polling';
  getLogger().warn('Summary polling fallback active.', { interval_ms: intervalMs });
}
