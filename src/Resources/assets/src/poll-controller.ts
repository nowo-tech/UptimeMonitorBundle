import { fetchSummary, type SummaryResponse } from './api-client';

/**
 * Polls the summary API on an interval with simple error backoff.
 */
export class PollController {
  private timer: ReturnType<typeof setInterval> | null = null;
  private lastSince: string | undefined;

  constructor(
    private readonly apiUrl: string,
    private readonly intervalMs: number,
    private readonly onData: (data: SummaryResponse) => void,
    private readonly onError: (error: Error) => void,
  ) {}

  start(): void {
    void this.tick();
    this.timer = setInterval(() => void this.tick(), this.intervalMs);
  }

  stop(): void {
    if (this.timer !== null) {
      clearInterval(this.timer);
      this.timer = null;
    }
  }

  private async tick(): Promise<void> {
    try {
      const data = await fetchSummary(this.apiUrl, this.lastSince);
      this.lastSince = data.server_time;
      this.onData(data);
    } catch (error) {
      this.onError(error instanceof Error ? error : new Error(String(error)));
    }
  }
}
