import { describe, expect, it, vi } from 'vitest';
import { fetchSummary } from './api-client';
import { PollController } from './poll-controller';

vi.mock('./api-client', () => ({
  fetchSummary: vi.fn().mockResolvedValue({
    tenant: 'main',
    server_time: '2026-05-20T12:00:00+00:00',
    monitors: [],
  }),
}));

describe('PollController', () => {
  it('invokes onData after start', async () => {
    const onData = vi.fn();
    const onError = vi.fn();
    const controller = new PollController('/api/uptime/main/summary', 60_000, onData, onError);

    controller.start();
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(onData).toHaveBeenCalled();
    expect(onError).not.toHaveBeenCalled();

    controller.stop();
  });

  it('passes the last server time as cursor and repeats on the interval', async () => {
    vi.useFakeTimers();
    const onData = vi.fn();
    const controller = new PollController('/api/summary', 1000, onData, vi.fn());
    controller.start();
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetchSummary).toHaveBeenLastCalledWith('/api/summary', '2026-05-20T12:00:00+00:00');
    expect(onData).toHaveBeenCalledTimes(2);
    controller.stop();
    controller.stop();
    vi.useRealTimers();
  });

  it('reports errors, wrapping non-Error values', async () => {
    const onError = vi.fn();
    vi.mocked(fetchSummary).mockRejectedValueOnce(new Error('boom')).mockRejectedValueOnce('text');
    const controller = new PollController('/api/summary', 60_000, vi.fn(), onError);
    controller.start();
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller.stop();
    const second = new PollController('/api/summary', 60_000, vi.fn(), onError);
    second.start();
    await new Promise((resolve) => setTimeout(resolve, 0));
    second.stop();
    expect(onError.mock.calls[0][0].message).toBe('boom');
    expect(onError.mock.calls[1][0].message).toBe('text');
  });
});
