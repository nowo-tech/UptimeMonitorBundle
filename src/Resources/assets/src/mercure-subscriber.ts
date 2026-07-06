import type { MercureDashboardMessage } from './dashboard-update';
import { applyMonitorUpdate } from './dashboard-update';
import {
  applyDashboardSnapshot,
  prependEventRow,
  refreshDashboardLayout,
  renderEventsTable,
  renderQuickStats,
  updateLastSync,
} from './dashboard-panel';
import { setMercureConnectionState, type MercureConnectionContext } from './dashboard-ui';

export type MercureSubscribeCallbacks = {
  onOpen?: () => void;
  onMessage?: () => void;
  onError?: (error: Error) => void;
};

/**
 * Subscribes to Mercure SSE hub for monitor updates (requires mercure authorization cookie).
 */
export function subscribeMercure(
  root: HTMLElement,
  hubUrl: string,
  topic: string,
  callbacks?: MercureSubscribeCallbacks | (() => void),
  onError?: (error: Error) => void,
): () => void {
  const handlers: MercureSubscribeCallbacks =
    typeof callbacks === 'function' ? { onMessage: callbacks, onError } : (callbacks ?? {});

  const mercureContext: MercureConnectionContext = { hub: hubUrl, topic };

  setMercureConnectionState(root, 'connecting', mercureContext);

  const url = new URL(hubUrl);
  url.searchParams.append('topic', topic);

  const eventSource = new EventSource(url.toString(), { withCredentials: true });

  eventSource.onopen = () => {
    setMercureConnectionState(root, 'connected', mercureContext);
    handlers.onOpen?.();
  };

  eventSource.onmessage = (event: MessageEvent<string>) => {
    try {
      const payload = JSON.parse(event.data) as MercureDashboardMessage;

      if (payload.type === 'dashboard_reset') {
        void (async () => {
          const refreshed = await refreshDashboardLayout(root);
          if (!refreshed) {
            await applyDashboardSnapshot(root, payload);
          } else {
            if (payload.stats !== undefined) {
              renderQuickStats(root, payload.stats);
            }
            if (payload.events !== undefined) {
              renderEventsTable(root, payload.events);
            }
            updateLastSync(root, payload.server_time);
          }
          handlers.onMessage?.();
        })();
        return;
      }

      if (payload.type !== 'monitor_update' || payload.monitor === undefined) {
        return;
      }

      applyMonitorUpdate(root, payload.monitor, { animateHistory: true });

      if (payload.stats !== undefined) {
        renderQuickStats(root, payload.stats);
      }

      if (payload.event !== undefined) {
        prependEventRow(root, payload.event);
      }

      if (payload.server_time !== '') {
        updateLastSync(root, payload.server_time);
      }

      handlers.onMessage?.();
    } catch {
      // Ignore malformed payloads.
    }
  };

  eventSource.onerror = () => {
    if (eventSource.readyState === EventSource.CONNECTING) {
      setMercureConnectionState(root, 'connecting', mercureContext);
      return;
    }

    if (eventSource.readyState === EventSource.OPEN) {
      return;
    }

    setMercureConnectionState(root, 'error', mercureContext);
    handlers.onError?.(new Error('Mercure connection closed'));
  };

  return () => {
    eventSource.close();
    setMercureConnectionState(root, 'disconnected', mercureContext);
  };
}
