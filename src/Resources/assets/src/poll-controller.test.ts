import { describe, expect, it, vi } from 'vitest';
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
});
