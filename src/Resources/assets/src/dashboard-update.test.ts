import { describe, expect, it } from 'vitest';
import type { MonitorSummaryItem } from './api-client';
import { applyMonitorUpdate } from './dashboard-update';

function monitor(overrides: Partial<MonitorSummaryItem> = {}): MonitorSummaryItem {
  return {
    id: 5,
    name: 'API',
    type: 'http',
    target: 'https://example.com',
    paused: false,
    interval_seconds: 60,
    next_check_at: null,
    last_status: 'up',
    last_latency_ms: 42,
    last_checked_at: '2026-01-01T10:00:00+00:00',
    last_status_code: 200,
    history: [{ status: 'up', checked_at: '2026-01-01T10:00:00+00:00' }],
    uptime_24h: 99.6,
    ...overrides,
  };
}

function mount(rowClass: string, slots = '3'): HTMLElement {
  const root = document.createElement('div');
  root.dataset.historySlots = slots;
  root.innerHTML = `
    <li class="${rowClass}" data-monitor-id="5" data-sidebar-row>
      <span data-status-cell></span><span data-latency-cell></span><span data-checked-cell></span>
      <span data-uptime-pill></span><div data-history-bar></div>
    </li>`;
  return root;
}

describe('applyMonitorUpdate', () => {
  it('updates the sidebar row cells, pill, history bar and flash class', () => {
    const root = mount('uptime-sidebar-item');
    applyMonitorUpdate(root, monitor(), { animateHistory: true });

    const row = root.querySelector('li') as HTMLElement;
    expect(row.querySelector('[data-status-cell]')?.textContent).toBe('up');
    expect(row.querySelector('[data-status-cell]')?.className).toBe('uptime-status uptime-status-up');
    expect(row.querySelector('[data-latency-cell]')?.textContent).toBe('42 ms');
    expect(row.querySelector('[data-checked-cell]')?.textContent).toBe('2026-01-01 10:00:00');
    expect(row.querySelector('[data-uptime-pill]')?.textContent).toBe('100%');
    expect(row.querySelector('[data-uptime-pill]')?.className).toBe('uptime-sidebar-pill uptime-sidebar-pill-up');
    expect(row.querySelectorAll('[data-history-bar] span')).toHaveLength(3);
    expect(row.classList.contains('uptime-sidebar-item--flash')).toBe(true);
  });

  it('shows placeholders for a paused monitor without data on a list row', () => {
    const root = mount('uptime-monitor-item', 'x');
    root.querySelector('li')?.classList.add('uptime-monitor-item');
    applyMonitorUpdate(
      root,
      monitor({ paused: true, last_status: null, last_latency_ms: null, last_checked_at: null, history: undefined, uptime_24h: null }),
    );

    const row = root.querySelector('li') as HTMLElement;
    expect(row.querySelector('[data-status-cell]')?.textContent).toBe('unknown');
    expect(row.querySelector('[data-latency-cell]')?.textContent).toBe('—');
    expect(row.querySelector('[data-checked-cell]')?.textContent).toBe('—');
    expect(row.querySelector('[data-uptime-pill]')?.textContent).toBe('—');
    expect(row.querySelector('[data-uptime-pill]')?.className).toBe('uptime-uptime-pill uptime-uptime-pill-unknown');
    expect(row.querySelectorAll('[data-history-bar] span')).toHaveLength(50);
    expect(row.classList.contains('uptime-monitor-item--flash')).toBe(true);
  });

  it('ignores monitors without id or without a row', () => {
    const root = mount('uptime-sidebar-item');
    applyMonitorUpdate(root, monitor({ id: null }));
    applyMonitorUpdate(root, monitor({ id: 99 }));
    expect(root.querySelector('[data-status-cell]')?.textContent).toBe('');
  });

  it('tolerates rows without optional cells', () => {
    const root = document.createElement('div');
    root.innerHTML = '<div class="uptime-monitor-item" data-monitor-id="5"></div>';
    applyMonitorUpdate(root, monitor());
    expect(root.querySelector('.uptime-monitor-item--flash')).not.toBeNull();
  });
});
