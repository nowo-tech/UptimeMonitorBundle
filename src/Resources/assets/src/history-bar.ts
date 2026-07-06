import type { HistorySegment } from './api-client';

export const HISTORY_BAR_SLOTS = 50;

/**
 * Pads segments to fixed slot count, aligned to the right (empty slots on the left).
 */
export function padHistorySegments(
  segments: HistorySegment[],
  slots: number = HISTORY_BAR_SLOTS,
): HistorySegment[] {
  const emptySlot: HistorySegment = { status: 'empty', checked_at: '' };
  const padded: HistorySegment[] = Array.from({ length: slots }, () => ({ ...emptySlot }));
  const count = Math.min(segments.length, slots);
  for (let i = 0; i < count; i += 1) {
    padded[slots - count + i] = segments[segments.length - count + i];
  }
  return padded;
}

/**
 * Renders fixed-slot vertical history bar (grey empty, green up, red down).
 */
export function renderHistoryBar(
  container: HTMLElement,
  history: HistorySegment[],
  options?: { animateLast?: boolean; slots?: number },
): void {
  const slots = options?.slots ?? HISTORY_BAR_SLOTS;
  const padded = padHistorySegments(history, slots);
  const previousLast = container.dataset.lastSegmentAt ?? '';
  const lastFilled = [...padded].reverse().find((s) => s.status !== 'empty');
  const newLast = lastFilled?.checked_at ?? '';
  const shouldAnimate =
    (options?.animateLast ?? false) &&
    newLast !== '' &&
    newLast !== previousLast;

  container.replaceChildren();

  padded.forEach((segment, index) => {
    const bar = document.createElement('span');
    const isNew =
      shouldAnimate &&
      segment.status !== 'empty' &&
      index === padded.length - 1;
    bar.className = `uptime-history-segment uptime-history-segment-${segment.status}${isNew ? ' uptime-history-segment--new' : ''}`;
    if (segment.status !== 'empty' && segment.checked_at !== '') {
      const checkedAt = segment.checked_at.replace('T', ' ').slice(0, 19);
      bar.title = `${checkedAt} — ${segment.status}`;
    }
    container.appendChild(bar);
  });

  container.dataset.lastSegmentAt = newLast;
}
