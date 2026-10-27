import { describe, expect, it } from 'vitest';
import { HISTORY_BAR_SLOTS, padHistorySegments, renderHistoryBar } from './history-bar';

const up = (at: string) => ({ status: 'up', checked_at: at });

describe('history bar', () => {
  it('pads to the slot count with empty slots on the left', () => {
    const padded = padHistorySegments([up('2026-01-01T00:00:00'), { status: 'down', checked_at: '2026-01-01T00:01:00' }], 4);
    expect(padded.map((s) => s.status)).toEqual(['empty', 'empty', 'up', 'down']);
    expect(padHistorySegments([]).length).toBe(HISTORY_BAR_SLOTS);
  });

  it('keeps the newest segments when there are more than slots', () => {
    const padded = padHistorySegments([up('a'), up('b'), up('c')], 2);
    expect(padded.map((s) => s.checked_at)).toEqual(['b', 'c']);
  });

  it('renders one span per slot with titles on filled slots', () => {
    const container = document.createElement('div');
    renderHistoryBar(container, [up('2026-01-01T10:00:00+00:00')], { slots: 3 });

    const bars = container.querySelectorAll('span');
    expect(bars).toHaveLength(3);
    expect(bars[0].className).toBe('uptime-history-segment uptime-history-segment-empty');
    expect(bars[0].title).toBe('');
    expect(bars[2].title).toBe('2026-01-01 10:00:00 — up');
    expect(container.dataset.lastSegmentAt).toBe('2026-01-01T10:00:00+00:00');
  });

  it('animates only a new last segment', () => {
    const container = document.createElement('div');
    renderHistoryBar(container, [up('t1')], { slots: 2, animateLast: true });
    expect(container.querySelector('.uptime-history-segment--new')).not.toBeNull();

    renderHistoryBar(container, [up('t1')], { slots: 2, animateLast: true });
    expect(container.querySelector('.uptime-history-segment--new')).toBeNull();

    renderHistoryBar(container, [], { slots: 2, animateLast: true });
    expect(container.querySelector('.uptime-history-segment--new')).toBeNull();
    expect(container.dataset.lastSegmentAt).toBe('');
  });
});
