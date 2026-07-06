import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchSummary } from './api-client';

describe('fetchSummary', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('returns parsed JSON on success', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({
          tenant: 'main',
          since: null,
          server_time: '2026-05-20T12:00:00+00:00',
          monitors: [],
        }),
      }),
    );

    const data = await fetchSummary('/api/uptime/main/summary');
    expect(data.tenant).toBe('main');
    expect(fetch).toHaveBeenCalledWith('/api/uptime/main/summary', {
      headers: { Accept: 'application/json' },
    });
  });

  it('throws when response is not ok', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({ ok: false, status: 500 }),
    );

    await expect(fetchSummary('/api/uptime/main/summary')).rejects.toThrow('500');
  });
});
