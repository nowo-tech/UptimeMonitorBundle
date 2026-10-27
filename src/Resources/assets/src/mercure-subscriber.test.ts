import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { subscribeMercure } from './mercure-subscriber';

class MockEventSource {
  static readonly CONNECTING = 0;
  static readonly OPEN = 1;
  static readonly CLOSED = 2;
  static instances: MockEventSource[] = [];

  onopen: (() => void) | null = null;
  onmessage: ((event: MessageEvent<string>) => void) | null = null;
  onerror: (() => void) | null = null;
  readyState = MockEventSource.CONNECTING;

  constructor(
    public readonly url: string,
    public readonly options?: { withCredentials?: boolean },
  ) {
    MockEventSource.instances.push(this);
  }

  open(): void {
    this.readyState = MockEventSource.OPEN;
    this.onopen?.();
  }

  send(data: unknown): void {
    this.onmessage?.(new MessageEvent('message', { data: typeof data === 'string' ? data : JSON.stringify(data) }));
  }

  fail(state: number): void {
    this.readyState = state;
    this.onerror?.();
  }

  close(): void {
    this.readyState = MockEventSource.CLOSED;
  }
}

const OriginalEventSource = globalThis.EventSource;

function mount(): { root: HTMLElement; badge: HTMLElement } {
  document.body.innerHTML = `
    <div class="uptime-sync-bar"><span data-sync-badge></span></div>
    <div id="root" data-layout-fragment="">
      <ul><li data-sidebar-row data-monitor-id="1"><a data-sidebar-name>API</a><span data-status-cell></span></li></ul>
      <span data-stat-up>0</span><span data-last-sync></span>
      <table><tbody data-events-body></tbody></table>
    </div>`;
  return { root: document.getElementById('root') as HTMLElement, badge: document.querySelector('[data-sync-badge]') as HTMLElement };
}

const MONITOR = {
  id: 1, name: 'API', type: 'http', target: 't', paused: false, interval_seconds: 60, next_check_at: null,
  last_status: 'down', last_latency_ms: 5, last_checked_at: null, last_status_code: 500, history: [], uptime_24h: 50,
};
const STATS = { up: 0, down: 1, degraded: 0, unknown: 0, paused: 0 };
const EVENT = { monitor_id: 1, monitor_name: 'API', status: 'down', checked_at: '2026-01-01T10:00:00+00:00', message: null, status_code: 500, is_group: false };

describe('subscribeMercure', () => {
  beforeEach(() => {
    MockEventSource.instances = [];
    globalThis.EventSource = MockEventSource as unknown as typeof EventSource;
  });

  afterEach(() => {
    globalThis.EventSource = OriginalEventSource;
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('opens the stream with the topic, marks the badge and closes', () => {
    const { root, badge } = mount();
    const onOpen = vi.fn();
    const close = subscribeMercure(root, 'http://localhost:8011/.well-known/mercure', '/uptime/main', { onOpen });

    const source = MockEventSource.instances[0];
    expect(source.url).toBe('http://localhost:8011/.well-known/mercure?topic=%2Fuptime%2Fmain');
    expect(source.options).toEqual({ withCredentials: true });
    expect(badge.dataset.mercureState).toBe('connecting');

    source.open();
    expect(badge.dataset.mercureState).toBe('connected');
    expect(onOpen).toHaveBeenCalledOnce();

    close();
    expect(badge.dataset.mercureState).toBe('disconnected');
    expect(source.readyState).toBe(MockEventSource.CLOSED);
  });

  it('applies monitor updates (row, stats, event, sync stamp)', () => {
    const { root } = mount();
    const onMessage = vi.fn();
    subscribeMercure(root, 'http://hub/.well-known/mercure', '/t', onMessage);
    const source = MockEventSource.instances[0];

    source.send({ type: 'monitor_update', server_time: '2026-01-01T10:00:00+00:00', monitor: MONITOR, stats: STATS, event: EVENT });
    expect(root.querySelector('[data-status-cell]')?.textContent).toBe('down');
    expect(root.querySelector('[data-stat-up]')?.textContent).toBe('0');
    expect(root.querySelectorAll('[data-event-row]')).toHaveLength(1);
    expect(root.querySelector('[data-last-sync]')?.textContent).toBe('2026-01-01 10:00:00');
    expect(onMessage).toHaveBeenCalledOnce();

    source.send({ type: 'monitor_update', server_time: '', monitor: MONITOR });
    expect(onMessage).toHaveBeenCalledTimes(2);
  });

  it('ignores unknown, incomplete and malformed payloads', () => {
    const { root } = mount();
    const onMessage = vi.fn();
    subscribeMercure(root, 'http://hub/m', '/t', { onMessage });
    const source = MockEventSource.instances[0];
    source.send({ type: 'other' });
    source.send({ type: 'monitor_update' });
    source.send('{not json');
    expect(onMessage).not.toHaveBeenCalled();
  });

  it('applies a dashboard reset as a snapshot when the layout cannot be refreshed', async () => {
    const { root } = mount();
    const onMessage = vi.fn();
    subscribeMercure(root, 'http://hub/m', '/t', { onMessage });
    MockEventSource.instances[0].send({
      type: 'dashboard_reset', tenant: 'main', since: null, server_time: '2026-01-01T12:00:00+00:00',
      monitors: [MONITOR], stats: STATS, events: [EVENT],
    });
    await vi.waitFor(() => expect(onMessage).toHaveBeenCalledOnce());
    expect(root.querySelector('[data-last-sync]')?.textContent).toBe('2026-01-01 12:00:00');
  });

  it('refreshes the layout on a dashboard reset and then renders stats / events', async () => {
    const { root } = mount();
    root.dataset.layoutFragment = '/frag';
    vi.stubGlobal('fetch', vi.fn().mockImplementation(async () => new Response('<div data-layout-fragment-root></div>')));
    const onMessage = vi.fn();
    subscribeMercure(root, 'http://hub/m', '/t', { onMessage });
    const source = MockEventSource.instances[0];

    source.send({ type: 'dashboard_reset', tenant: 'main', since: null, server_time: '2026-01-01T12:00:00+00:00', monitors: [], stats: { ...STATS, up: 7 }, events: [] });
    await vi.waitFor(() => expect(onMessage).toHaveBeenCalledOnce());
    expect(root.querySelector('[data-stat-up]')?.textContent).toBe('7');
    expect(root.querySelector('[data-events-empty]')).not.toBeNull();

    source.send({ type: 'dashboard_reset', tenant: 'main', since: null, server_time: '2026-01-01T13:00:00+00:00', monitors: [] });
    await vi.waitFor(() => expect(onMessage).toHaveBeenCalledTimes(2));
    expect(root.querySelector('[data-last-sync]')?.textContent).toBe('2026-01-01 13:00:00');
  });

  it('handles reconnecting, transient and fatal errors', () => {
    const { root, badge } = mount();
    const onError = vi.fn();
    subscribeMercure(root, 'http://hub/m', '/t', () => undefined, onError);
    const source = MockEventSource.instances[0];

    source.fail(MockEventSource.CONNECTING);
    expect(badge.dataset.mercureState).toBe('connecting');
    source.fail(MockEventSource.OPEN);
    expect(onError).not.toHaveBeenCalled();
    source.fail(MockEventSource.CLOSED);
    expect(badge.dataset.mercureState).toBe('error');
    expect(onError).toHaveBeenCalledWith(expect.any(Error));
  });

  it('works without callbacks', () => {
    const { root } = mount();
    subscribeMercure(root, 'http://hub/m', '/t');
    const source = MockEventSource.instances[0];
    expect(() => {
      source.open();
      source.send({ type: 'monitor_update', server_time: '', monitor: MONITOR });
      source.fail(MockEventSource.CLOSED);
    }).not.toThrow();
  });
});
