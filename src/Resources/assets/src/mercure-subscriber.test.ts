import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { subscribeMercure } from './mercure-subscriber';

describe('subscribeMercure', () => {
  const OriginalEventSource = globalThis.EventSource;

  beforeEach(() => {
    class MockEventSource {
      static readonly CONNECTING = 0;
      static readonly OPEN = 1;
      static readonly CLOSED = 2;

      onopen: (() => void) | null = null;
      onmessage: ((event: MessageEvent<string>) => void) | null = null;
      onerror: (() => void) | null = null;
      readyState = MockEventSource.CONNECTING;

      constructor(
        public readonly url: string,
        public readonly options?: { withCredentials?: boolean },
      ) {
        queueMicrotask(() => {
          this.readyState = MockEventSource.OPEN;
          this.onopen?.();
        });
      }

      close(): void {
        this.readyState = MockEventSource.CLOSED;
      }
    }
    globalThis.EventSource = MockEventSource as unknown as typeof EventSource;
  });

  afterEach(() => {
    globalThis.EventSource = OriginalEventSource;
  });

  it('opens EventSource with topic query param and marks badge connected', async () => {
    document.body.innerHTML = `
      <div class="uptime-sync-bar">
        <span class="uptime-sync-badge" data-sync-badge>Mercure · connecting…</span>
      </div>
      <div id="root"></div>
    `;
    const root = document.getElementById('root') as HTMLElement;
    root.innerHTML =
      '<table><tbody><tr data-monitor-id="1"><td data-status-cell></td><td data-latency-cell></td><td data-checked-cell></td></tr></tbody></table>';

    const close = subscribeMercure(
      root,
      'http://localhost:8011/.well-known/mercure',
      '/uptime/main',
      { onOpen: () => undefined },
    );

    await new Promise((resolve) => queueMicrotask(resolve));

    const badge = document.querySelector<HTMLElement>('[data-sync-badge]');
    expect(badge?.dataset.mercureState).toBe('connected');
    expect(badge?.textContent).toBe('Mercure · connected');

    close();
    expect(badge?.dataset.mercureState).toBe('disconnected');
  });
});
