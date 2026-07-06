/**
 * Fetches uptime summary JSON for dashboard polling.
 */
export type HistorySegment = {
  status: string;
  checked_at: string;
};

export type MonitorSummaryItem = {
  id: number | null;
  name: string;
  project?: string | null;
  type: string;
  target: string;
  paused: boolean;
  interval_seconds: number;
  next_check_at: string | null;
  last_status: string | null;
  last_latency_ms: number | null;
  last_checked_at: string | null;
  last_status_code: number | null;
  history?: HistorySegment[];
  uptime_24h?: number | null;
};

export type TenantQuickStats = {
  up: number;
  down: number;
  degraded: number;
  unknown: number;
  paused: number;
};

export type TenantEventRow = {
  monitor_id: number | null;
  monitor_name: string;
  status: string;
  checked_at: string;
  message: string | null;
  status_code: number | null;
  is_group: boolean;
};

export type SummaryResponse = {
  tenant: string;
  since: string | null;
  server_time: string;
  monitors: MonitorSummaryItem[];
  stats?: TenantQuickStats;
  events?: TenantEventRow[];
};

/**
 * @param url Summary API URL (includes tenant in path).
 * @param since Optional ISO timestamp for delta polling.
 */
export async function fetchSummary(url: string, since?: string): Promise<SummaryResponse> {
  const query = since ? `?since=${encodeURIComponent(since)}` : '';
  const response = await fetch(`${url}${query}`, {
    headers: { Accept: 'application/json' },
  });

  if (!response.ok) {
    throw new Error(`Summary request failed: ${response.status}`);
  }

  return (await response.json()) as SummaryResponse;
}
