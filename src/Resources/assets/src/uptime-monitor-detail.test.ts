import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const chartMock = vi.hoisted(() => vi.fn());
vi.mock('chart.js/auto', () => ({ Chart: chartMock }));

import { applyHistoryPayload, bootstrap, parseJson, pollHistory, type HistoryPayload } from './uptime-monitor-detail';

const PAYLOAD: HistoryPayload = {
  last_status: 'down',
  last_checked_at: '2026-01-01T10:00:00+00:00',
  uptime_24h: 97.254,
  uptime_30d: 99.1,
  history: [{ status: 'down', checked_at: '2026-01-01T10:00:00+00:00' }],
  latency_series: { labels: [], latency_ms: [] },
};

function mount(attrs: Record<string, string> = {}): HTMLElement {
  document.documentElement.replaceChild(document.createElement('body'), document.body);
  document.body.innerHTML = `
    <div id="uptime-monitor-detail-root">
      <span data-detail-status></span><span data-detail-checked>—</span>
      <span data-uptime-24h></span><span data-uptime-30d></span>
      <div data-history-bar></div>
      <canvas id="uptime-chart-response"></canvas><canvas id="uptime-chart-uptime"></canvas><canvas id="uptime-chart-latency"></canvas>
    </div>`;
  const root = document.getElementById('uptime-monitor-detail-root') as HTMLElement;
  Object.assign(root.dataset, attrs);
  return root;
}

describe('monitor detail', () => {
  beforeEach(() => chartMock.mockClear());

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it('parses JSON data attributes defensively', () => {
    expect(parseJson<{ a: number }>('{"a":1}')).toEqual({ a: 1 });
    expect(parseJson(undefined)).toBeNull();
    expect(parseJson('')).toBeNull();
    expect(parseJson('{bad')).toBeNull();
  });

  it('applies the history payload', () => {
    const root = mount();
    applyHistoryPayload(root, PAYLOAD);
    expect(root.querySelector('[data-detail-status]')?.className).toBe('uptime-detail-status uptime-status-down');
    expect(root.querySelector('[data-detail-checked]')?.textContent).toBe('2026-01-01 10:00:00');
    expect(root.querySelector('[data-uptime-24h]')?.textContent).toBe('97.3%');
    expect(root.querySelector('[data-uptime-30d]')?.textContent).toBe('99.1%');
    expect(root.querySelectorAll('[data-history-bar] span')).toHaveLength(50);

    applyHistoryPayload(root, { ...PAYLOAD, last_status: null, last_checked_at: null, uptime_24h: null, uptime_30d: null });
    expect(root.querySelector('[data-detail-status]')?.textContent).toBe('unknown');
    expect(root.querySelector('[data-detail-checked]')?.textContent).toBe('2026-01-01 10:00:00');
    expect(root.querySelector('[data-uptime-24h]')?.textContent).toBe('—');

    const bare = document.createElement('div');
    expect(() => applyHistoryPayload(bare, PAYLOAD)).not.toThrow();
  });

  it('reveals the history, figures and log once the first check arrives', () => {
    const root = mount();
    root.insertAdjacentHTML('beforeend', '<p data-detail-unchecked>Not checked yet</p><div data-detail-pending hidden></div>');

    applyHistoryPayload(root, { ...PAYLOAD, last_checked_at: null });
    expect(root.querySelector('[data-detail-unchecked]')).not.toBeNull();
    expect(root.querySelector<HTMLElement>('[data-detail-pending]')?.hidden).toBe(true);

    applyHistoryPayload(root, PAYLOAD);
    expect(root.querySelector('[data-detail-unchecked]')).toBeNull();
    expect(root.querySelector<HTMLElement>('[data-detail-pending]')?.hidden).toBe(false);
  });

  it('polls the history API and keeps the page on errors', async () => {
    const root = mount();
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify(PAYLOAD)))
      .mockResolvedValueOnce(new Response('', { status: 503 }))
      .mockRejectedValueOnce(new Error('offline')));
    await pollHistory(root, '/api/history');
    expect(root.querySelector('[data-detail-status]')?.textContent).toBe('down');
    root.querySelector('[data-detail-status]')!.textContent = 'kept';
    await pollHistory(root, '/api/history');
    await pollHistory(root, '/api/history');
    expect(root.querySelector('[data-detail-status]')?.textContent).toBe('kept');
  });

  it('renders the three charts, polls every 15 s and binds the monitor dialog', async () => {
    vi.useFakeTimers();
    const fetchMock = vi.fn().mockImplementation(async () => new Response(JSON.stringify(PAYLOAD)));
    vi.stubGlobal('fetch', fetchMock);
    mount({
      series: JSON.stringify({ labels: ['d1'], uptime_percent: [100], latency_avg_ms: [5] }),
      latencySeries: JSON.stringify({ labels: ['t1'], latency_ms: [5] }),
      apiHistory: '/api/history',
    });

    bootstrap();
    expect(chartMock).toHaveBeenCalledTimes(3);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(15000);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('skips charts without data and does nothing without its root', () => {
    mount({ series: JSON.stringify({ labels: [], uptime_percent: [], latency_avg_ms: [] }), latencySeries: JSON.stringify({ labels: [], latency_ms: [] }) });
    bootstrap();
    expect(chartMock).not.toHaveBeenCalled();

    mount({ series: '{bad' });
    document.querySelectorAll('canvas').forEach((c) => c.remove());
    bootstrap();
    expect(chartMock).not.toHaveBeenCalled();

    document.body.innerHTML = '';
    expect(() => bootstrap()).not.toThrow();
  });
});
