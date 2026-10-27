import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mercure = vi.hoisted(() => ({ subscribeMercure: vi.fn() }));
vi.mock('./mercure-subscriber', () => mercure);

import { bootstrap, bootstrapMercure, bootstrapPolling, syncFromSummary } from './uptime-dashboard';
import { clearBundleLoggerForTest } from './uptime-logger';

const MONITOR = {
  id: 1, name: 'API', type: 'http', target: 't', paused: false, interval_seconds: 60, next_check_at: null,
  last_status: 'down', last_latency_ms: 5, last_checked_at: null, last_status_code: 500, history: [], uptime_24h: 50,
};

function summary(extra: Record<string, unknown> = {}): Response {
  return new Response(JSON.stringify({
    tenant: 'main', since: null, server_time: '2026-01-01T10:00:00+00:00', monitors: [MONITOR],
    stats: { up: 0, down: 1, degraded: 0, unknown: 0, paused: 0 },
    events: [{ monitor_id: 1, monitor_name: 'API', status: 'down', checked_at: '2026-01-01T10:00:00+00:00', message: null, status_code: 500, is_group: false }],
    ...extra,
  }));
}

function mount(attrs: Record<string, string>): HTMLElement {
  document.documentElement.replaceChild(document.createElement('body'), document.body);
  document.body.innerHTML = `
    <div class="uptime-sync-bar"><span data-live-indicator></span><span data-sync-badge></span><span data-last-sync></span></div>
    <div id="uptime-dashboard-root" data-layout-fragment="">
      <input data-monitor-search>
      <ul><li data-sidebar-row data-monitor-id="1"><a data-sidebar-name>API</a><span data-status-cell></span></li></ul>
      <span data-stat-down>0</span><span data-last-sync></span>
      <table><tbody data-events-body></tbody></table>
    </div>`;
  const root = document.getElementById('uptime-dashboard-root') as HTMLElement;
  Object.assign(root.dataset, attrs);
  return root;
}

describe('dashboard bootstrap', () => {
  beforeEach(() => {
    mercure.subscribeMercure.mockReset();
    vi.spyOn(console, 'log').mockImplementation(() => {});
    vi.spyOn(console, 'info').mockImplementation(() => {});
    vi.spyOn(console, 'warn').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    clearBundleLoggerForTest();
    window.history.replaceState(null, '', '/');
  });

  it('polls the summary API and applies rows, stats and events', async () => {
    vi.useFakeTimers();
    const fetchMock = vi.fn().mockImplementation(async () => summary());
    vi.stubGlobal('fetch', fetchMock);
    const root = mount({ sync: 'polling', apiSummary: '/api/summary', pollInterval: '1000', eventsFilter: '1' });

    bootstrap();
    await vi.advanceTimersByTimeAsync(0);
    expect(root.dataset.transport).toBe('polling');
    expect(root.querySelector('[data-status-cell]')?.textContent).toBe('down');
    expect(root.querySelector('[data-stat-down]')?.textContent).toBe('1');
    expect(root.querySelectorAll('[data-event-row]')).toHaveLength(1);
    expect(root.querySelector('[data-last-sync]')?.textContent).toBe('2026-01-01 10:00:00');

    await vi.advanceTimersByTimeAsync(1000);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('refreshes the layout when the monitor tree changed and keeps the page on API errors', async () => {
    vi.useFakeTimers();
    const root = mount({ apiSummary: '/api/summary', layoutFragment: '/frag' });
    vi.stubGlobal('fetch', vi.fn()
      .mockImplementationOnce(async () => summary({ monitors: [{ ...MONITOR, id: 2, name: 'New' }] }))
      .mockImplementationOnce(async () => new Response('<div data-layout-fragment-root></div>'))
      .mockImplementationOnce(async () => new Response('', { status: 500 }))
      .mockImplementationOnce(async () => summary({ stats: undefined, events: undefined })));

    bootstrapPolling(root, '/api/summary', 1000);
    await vi.advanceTimersByTimeAsync(0);
    await vi.advanceTimersByTimeAsync(1000);
    await vi.advanceTimersByTimeAsync(1000);
    expect(root.querySelector('[data-last-sync]')?.textContent).toBe('2026-01-01 10:00:00');
  });

  it('does not poll without a summary URL and only binds the form toggle without a dashboard', () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    mount({ apiSummary: '' });
    bootstrap();
    expect(fetchMock).not.toHaveBeenCalled();

    document.body.innerHTML = '<select data-monitor-type-select><option value="dns" selected>dns</option></select><fieldset data-monitor-fields="http"></fieldset>';
    bootstrap();
    expect(document.querySelector<HTMLElement>('[data-monitor-fields="http"]')?.hidden).toBe(true);
  });

  it('uses Mercure when configured and falls back to polling after an error', async () => {
    vi.useFakeTimers();
    const fetchMock = vi.fn().mockImplementation(async () => summary());
    vi.stubGlobal('fetch', fetchMock);
    const root = mount({ sync: 'mercure', mercureHub: 'http://hub/m', mercureTopic: '/t', apiSummary: '/api/summary', pollInterval: '1000' });

    bootstrap();
    expect(mercure.subscribeMercure).toHaveBeenCalledWith(root, 'http://hub/m', '/t', expect.any(Object));
    expect(root.dataset.transport).toBe('mercure');
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchMock).toHaveBeenCalledTimes(1);

    const handlers = mercure.subscribeMercure.mock.calls[0][3] as { onOpen: () => void; onMessage: () => void; onError: () => void };
    handlers.onError();
    handlers.onOpen();
    handlers.onMessage();
    handlers.onError();
    handlers.onError();
    await vi.advanceTimersByTimeAsync(4000);
    expect(root.dataset.transport).toBe('polling');
    expect(document.querySelector('[data-sync-badge]')?.textContent).toBe('Polling · 1s');
    handlers.onError();
    await vi.advanceTimersByTimeAsync(4000);
  });

  it('starts Mercure without a summary URL and swallows a failing first sync', async () => {
    vi.useFakeTimers();
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
    const root = mount({});
    bootstrapMercure(root, 'http://hub/m', '/t');
    const handlers = mercure.subscribeMercure.mock.calls[0][3] as { onError: () => void };
    handlers.onError();
    await vi.advanceTimersByTimeAsync(4000);
    expect(root.dataset.transport).toBe('mercure');

    const withApi = mount({ apiSummary: '/api/summary' });
    bootstrapMercure(withApi, 'http://hub/m', '/t');
    await vi.advanceTimersByTimeAsync(0);
    expect(withApi.dataset.transport).toBe('mercure');
  });

  it('syncs one snapshot', async () => {
    vi.stubGlobal('fetch', vi.fn().mockImplementation(async () => summary()));
    const root = mount({});
    await syncFromSummary(root, '/api/summary');
    expect(root.querySelector('[data-stat-down]')?.textContent).toBe('1');
  });
});
